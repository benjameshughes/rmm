use anyhow::{Context, Result};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use tokio::sync::{mpsc, RwLock};
use tokio_util::sync::CancellationToken;
use tracing::{debug, error, info, warn};

use crate::config::Config;
use crate::enrollment::{EnrollmentManager, EnrollmentStatus};
use crate::commands::CommandClient;
use crate::metrics::{KeyHealth, MetricsCollector};
use crate::power::{GatePolicy, PowerController, PowerNotifier};
use crate::power_events;
use crate::power_state::{PowerNotice, PowerReason};
use crate::storage::Storage;
use crate::sysinfo::SystemInfo;
use crate::updater::Updater;

/// Why a metrics session (one API key) ended
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum SessionEnd {
    /// Graceful shutdown requested
    Shutdown,
    /// The server kept rejecting the API key (401) - re-enroll
    KeyRejected,
}

/// Decide why a session ended. Shutdown always wins so a service stop never
/// deletes the key.
fn session_end(key_rejected: bool, shutdown_requested: bool) -> SessionEnd {
    if key_rejected && !shutdown_requested {
        SessionEnd::KeyRejected
    } else {
        SessionEnd::Shutdown
    }
}

/// Agent state
#[derive(Debug, Clone, PartialEq)]
pub enum AgentState {
    /// Not yet enrolled
    NotEnrolled,
    /// Enrollment submitted, waiting for approval
    PendingApproval,
    /// Enrolled and active
    Active,
    /// Revoked by server
    Revoked,
    /// Error state
    Error(String),
}

impl AgentState {
    /// Get a display string for the state
    pub fn as_display(&self) -> String {
        match self {
            AgentState::NotEnrolled => "Not Enrolled".to_string(),
            AgentState::PendingApproval => "Pending Approval".to_string(),
            AgentState::Active => "Online".to_string(),
            AgentState::Revoked => "Revoked".to_string(),
            AgentState::Error(msg) => format!("Error: {}", msg),
        }
    }

    /// Get a status label
    pub fn status_label(&self) -> String {
        format!("Status: {}", self.as_display())
    }
}

/// The key the current session authenticates with, for power notices.
#[derive(Clone)]
struct SessionAuth {
    api_key: String,
    key_health: Arc<KeyHealth>,
}

/// Main RMM Agent
pub struct Agent {
    config: Config,
    system_info: SystemInfo,
    enrollment_manager: EnrollmentManager,
    state: Arc<RwLock<AgentState>>,
    cancellation_token: CancellationToken,
    power: Arc<PowerController>,
    /// Taken by `run()`, which delivers the notices.
    power_notices: Mutex<Option<mpsc::UnboundedReceiver<PowerNotice>>>,
    /// Set while a metrics session is running.
    session_auth: Mutex<Option<SessionAuth>>,
    boot_announced: AtomicBool,
}

impl Agent {
    /// Create a new agent instance with default config
    pub async fn new() -> Result<Self> {
        Self::with_config(Config::default()).await
    }

    /// Create agent with a specific config (for URL override)
    pub async fn with_config(config: Config) -> Result<Self> {
        config
            .ensure_data_dir()
            .context("Failed to create data directory")?;

        let system_info = SystemInfo::gather().context("Failed to gather system information")?;
        info!("System info: {}", system_info.summary());

        let storage = Storage::new(&config.key_file);
        let enrollment_manager = EnrollmentManager::new(config.clone(), storage)?;

        // Determine initial state
        let initial_state = if enrollment_manager.is_enrolled().await {
            AgentState::Active
        } else {
            AgentState::NotEnrolled
        };

        let modern_standby = power_events::modern_standby_supported();
        info!(
            "Power model: {}",
            if modern_standby { "Modern Standby" } else { "classic sleep" }
        );
        let (power, power_notices) = PowerController::new(modern_standby);

        Ok(Self {
            config,
            system_info,
            enrollment_manager,
            state: Arc::new(RwLock::new(initial_state)),
            cancellation_token: CancellationToken::new(),
            power,
            power_notices: Mutex::new(Some(power_notices)),
            session_auth: Mutex::new(None),
            boot_announced: AtomicBool::new(false),
        })
    }

    /// Get the current agent state
    pub async fn get_state(&self) -> AgentState {
        self.state.read().await.clone()
    }

    /// Set the agent state
    async fn set_state(&self, state: AgentState) {
        let mut current = self.state.write().await;
        if *current != state {
            info!("Agent state changed: {:?} -> {:?}", *current, state);
            *current = state;
        }
    }

    /// Power state shared with the service control handler, which feeds it
    /// OS power events.
    pub fn power(&self) -> Arc<PowerController> {
        self.power.clone()
    }

    /// Get the cancellation token for graceful shutdown
    pub fn cancellation_token(&self) -> CancellationToken {
        self.cancellation_token.clone()
    }

    /// Start the agent (blocking - runs until cancelled)
    ///
    /// Loops: enroll if there is no key, then run the metrics session. If the
    /// server keeps rejecting the key (see `KeyHealth`), the key is discarded
    /// and the agent goes back through enrollment and approval.
    pub async fn run(self: Arc<Self>) -> Result<()> {
        info!("Starting RMM Agent");
        info!("Device: {}", self.system_info.hostname);
        info!(
            "Fingerprint: {}...",
            self.system_info.fingerprint_prefix()
        );
        info!("Server URL: {}", self.config.base_url);

        let power_task = self.spawn_power_task();
        let result = self.clone().run_sessions().await;
        if let Some(task) = power_task {
            task.abort();
        }

        result
    }

    async fn run_sessions(self: Arc<Self>) -> Result<()> {
        while !self.cancellation_token.is_cancelled() {
            let api_key = match self.enrollment_manager.get_api_key().await? {
                Some(api_key) => {
                    info!("Device is enrolled, starting metrics collection");
                    api_key
                }
                None => {
                    info!("Device not enrolled, starting enrollment process");
                    match self.enroll_and_wait_for_key().await {
                        Some(api_key) => api_key,
                        None => return Ok(()),
                    }
                }
            };

            self.set_state(AgentState::Active).await;

            match self.run_metrics_loop(api_key).await {
                SessionEnd::Shutdown => break,
                SessionEnd::KeyRejected => {
                    warn!("Server no longer accepts this device's API key - re-enrolling");
                    if let Err(e) = self.enrollment_manager.clear_api_key().await {
                        let msg = format!("Failed to clear rejected API key: {}", e);
                        error!("{}", msg);
                        self.set_state(AgentState::Error(msg)).await;
                        return Err(e);
                    }
                    self.set_state(AgentState::NotEnrolled).await;
                }
            }
        }

        Ok(())
    }

    /// Run enrollment and wait for approval. Returns the API key once the
    /// device is approved, or None if enrollment failed or was cancelled.
    async fn enroll_and_wait_for_key(&self) -> Option<String> {
        info!("Enrolling device with backend");

        // Submit enrollment request (with built-in retry logic)
        match self
            .enrollment_manager
            .enroll(&self.system_info, self.cancellation_token.clone())
            .await
        {
            Ok(_) => {
                // Enrollment submitted successfully
                self.set_state(AgentState::PendingApproval).await;
            }
            Err(e) => {
                // Only fails if explicitly rejected or cancelled
                let msg = format!("Enrollment failed: {}", e);
                error!("{}", msg);
                self.set_state(AgentState::Error(msg)).await;
                return None;
            }
        }

        // Wait for approval
        info!("Waiting for approval from administrator...");
        match self
            .enrollment_manager
            .wait_for_approval(&self.system_info, self.cancellation_token.clone())
            .await
        {
            Ok(_) => {
                info!("Device approved!");
                match self.enrollment_manager.get_api_key().await {
                    Ok(Some(api_key)) => Some(api_key),
                    Ok(None) => {
                        let msg = "Device approved but no API key found".to_string();
                        error!("{}", msg);
                        self.set_state(AgentState::Error(msg)).await;
                        None
                    }
                    Err(e) => {
                        let msg = format!("Device approved but API key unreadable: {}", e);
                        error!("{}", msg);
                        self.set_state(AgentState::Error(msg)).await;
                        None
                    }
                }
            }
            Err(e) => {
                let msg = format!("Enrollment approval failed: {}", e);
                error!("{}", msg);
                self.set_state(AgentState::Error(msg)).await;
                None
            }
        }
    }

    /// Run the metrics, heartbeat and update loops for one key (blocks until
    /// shutdown or until the key is declared dead).
    async fn run_metrics_loop(&self, api_key: String) -> SessionEnd {
        info!("Starting metrics, heartbeat, and update check loops");

        // Cancelled on shutdown (parent token) or when the key is rejected.
        let session_token = self.cancellation_token.child_token();
        let key_health = Arc::new(KeyHealth::new(session_token.clone()));

        let collector = match MetricsCollector::new(
            self.config.clone(),
            self.system_info.hostname.clone(),
            self.system_info.mac_addresses(),
        ) {
            Ok(c) => c,
            Err(e) => {
                error!("Failed to create metrics collector: {}", e);
                return SessionEnd::Shutdown;
            }
        };

        // Check if Netdata is available
        if !collector.check_netdata_available().await {
            warn!("Netdata is not available - metrics collection will be limited");
            warn!("Please ensure Netdata is installed and running");
        }

        // Create a separate collector for heartbeat loop
        let heartbeat_collector = match MetricsCollector::new(
            self.config.clone(),
            self.system_info.hostname.clone(),
            self.system_info.mac_addresses(),
        ) {
            Ok(c) => c,
            Err(e) => {
                error!("Failed to create heartbeat collector: {}", e);
                return SessionEnd::Shutdown;
            }
        };

        self.set_session_auth(Some(SessionAuth {
            api_key: api_key.clone(),
            key_health: key_health.clone(),
        }));
        if !self.boot_announced.swap(true, Ordering::SeqCst) {
            self.power
                .announce(PowerNotice::powering_on(PowerReason::Boot));
        }

        // Spawn heartbeat loop as a separate task
        let heartbeat_api_key = api_key.clone();
        let heartbeat_token = session_token.clone();
        let heartbeat_health = key_health.clone();
        let heartbeat_gate = self.power.gate(GatePolicy::Reporting);
        let heartbeat_handle = tokio::spawn(async move {
            heartbeat_collector
                .start_heartbeat_loop(
                    heartbeat_api_key,
                    heartbeat_token,
                    heartbeat_health,
                    heartbeat_gate,
                )
                .await;
        });

        let command_client = match CommandClient::new(self.config.clone()) {
            Ok(client) => client,
            Err(e) => {
                error!("Failed to create command client: {}", e);
                return SessionEnd::Shutdown;
            }
        };
        let command_api_key = api_key.clone();
        let command_token = session_token.clone();
        let command_health = key_health.clone();
        let command_power = self.power.clone();
        let command_handle = tokio::spawn(async move {
            command_client
                .start_command_loop(command_api_key, command_token, command_health, command_power)
                .await;
        });

        // Spawn update check loop as a separate task
        let update_config = self.config.clone();
        let update_token = session_token.clone();
        let update_handle = tokio::spawn(async move {
            match Updater::new(update_config) {
                Ok(updater) => {
                    updater.start_update_loop(update_token).await;
                }
                Err(e) => {
                    error!("Failed to create updater: {}", e);
                }
            }
        });

        // Start the metrics loop (blocks until the session is cancelled)
        collector
            .start_metrics_loop(
                api_key,
                session_token.clone(),
                key_health.clone(),
                self.power.gate(GatePolicy::Reporting),
            )
            .await;

        // Make sure the other loops stop too, then wait for them
        session_token.cancel();
        let _ = heartbeat_handle.await;
        let _ = command_handle.await;
        let _ = update_handle.await;
        self.set_session_auth(None);

        session_end(
            key_health.key_rejected(),
            self.cancellation_token.is_cancelled(),
        )
    }

    fn set_session_auth(&self, auth: Option<SessionAuth>) {
        *self
            .session_auth
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner()) = auth;
    }

    fn session_auth(&self) -> Option<SessionAuth> {
        self.session_auth
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
            .clone()
    }

    /// Deliver power notices in order for as long as the agent runs. A
    /// shutdown notice stops the agent once sent (or timed out).
    fn spawn_power_task(self: &Arc<Self>) -> Option<tokio::task::JoinHandle<()>> {
        let mut notices = self
            .power_notices
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
            .take()?;

        // Without a notifier the task still has to stop the agent on shutdown.
        let notifier = PowerNotifier::new(self.config.clone())
            .map_err(|e| error!("Failed to create power notifier: {}", e))
            .ok();

        let agent = self.clone();
        Some(tokio::spawn(async move {
            while let Some(notice) = notices.recv().await {
                if let Some(notifier) = &notifier {
                    agent.deliver_power_notice(notifier, notice).await;
                }

                if notice.is_shutdown() {
                    info!("Machine is shutting down, stopping the agent");
                    agent.shutdown();
                }
            }
        }))
    }

    async fn deliver_power_notice(&self, notifier: &PowerNotifier, notice: PowerNotice) {
        let Some(auth) = self.session_auth() else {
            debug!("Not enrolled yet, not sending power notice {:?}", notice);
            return;
        };

        let delivery = notifier.send(&auth.api_key, notice).await;
        auth.key_health.record(delivery.key_outcome());
    }

    /// Trigger graceful shutdown
    pub fn shutdown(&self) {
        info!("Initiating graceful shutdown");
        self.cancellation_token.cancel();
    }

    /// Check current status with backend
    pub async fn check_status(&self) -> Result<AgentState> {
        debug!("Checking status with backend");

        match self.enrollment_manager.check_status(&self.system_info).await {
            Ok(EnrollmentStatus::Approved) => {
                self.set_state(AgentState::Active).await;
                Ok(AgentState::Active)
            }
            Ok(EnrollmentStatus::Pending) => {
                self.set_state(AgentState::PendingApproval).await;
                Ok(AgentState::PendingApproval)
            }
            Ok(EnrollmentStatus::Revoked) => {
                self.set_state(AgentState::Revoked).await;
                Ok(AgentState::Revoked)
            }
            Ok(EnrollmentStatus::Unknown(status)) => {
                let msg = format!("Unknown status: {}", status);
                warn!("{}", msg);
                let state = AgentState::Error(msg);
                self.set_state(state.clone()).await;
                Ok(state)
            }
            Err(e) => {
                let msg = format!("Status check failed: {}", e);
                debug!("{}", msg);
                // Don't update state on transient errors
                Ok(self.get_state().await)
            }
        }
    }

    /// Clear API key and force re-enrollment
    pub async fn reset(&self) -> Result<()> {
        info!("Resetting agent - clearing API key");
        self.enrollment_manager.clear_api_key().await?;
        self.set_state(AgentState::NotEnrolled).await;
        Ok(())
    }

    /// Get system information
    pub fn system_info(&self) -> &SystemInfo {
        &self.system_info
    }

    /// Get the config
    pub fn config(&self) -> &Config {
        &self.config
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn shutdown_wins_over_key_rejection() {
        assert_eq!(session_end(false, false), SessionEnd::Shutdown);
        assert_eq!(session_end(false, true), SessionEnd::Shutdown);
        assert_eq!(session_end(true, true), SessionEnd::Shutdown);
        assert_eq!(session_end(true, false), SessionEnd::KeyRejected);
    }
}
