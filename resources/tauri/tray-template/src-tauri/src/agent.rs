use anyhow::{Context, Result};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use tokio::sync::{mpsc, RwLock};
use tokio_util::sync::CancellationToken;
use tracing::{debug, error, info, warn};

use crate::config::Config;
use crate::enrollment::{EnrollmentManager, EnrollmentStatus};
#[cfg(windows)]
use crate::commands::CommandClient;
use crate::metrics::{KeyHealth, MetricsCollector};
use crate::power::{self, PowerController, PowerNotifier};
use crate::power_state::{self, PowerNotice, PowerReason};
use crate::startup_grace::{self, Milestone, StartupProgress};
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
    /// Milestones for the startup keep-awake (no-op until the first session).
    startup: Mutex<StartupProgress>,
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

        let (power, power_notices) = PowerController::new();

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
            startup: Mutex::new(StartupProgress::default()),
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
        let watchdog = tokio::spawn(power::run_asleep_watchdog(
            self.power.clone(),
            std::time::Duration::from_secs(self.config.asleep_watchdog_interval),
            self.cancellation_token.clone(),
        ));
        let result = self.clone().run_sessions().await;
        if let Some(task) = power_task {
            task.abort();
        }
        watchdog.abort();

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

        // Windows reads metrics from Netdata; other builds collect natively.
        if cfg!(windows) && !collector.check_netdata_available().await {
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
            // Hold the machine awake until this agent has checked in: after a
            // self-update nothing else keeps a dark-woken PC up.
            *self
                .startup
                .lock()
                .unwrap_or_else(|poisoned| poisoned.into_inner()) =
                startup_grace::hold_while_starting(
                    std::time::Duration::from_secs(self.config.startup_keep_awake_max),
                    self.cancellation_token.clone(),
                );
            self.announce_boot_if_fresh();
        }

        // Spawn heartbeat loop as a separate task
        let heartbeat_api_key = api_key.clone();
        let heartbeat_token = session_token.clone();
        let heartbeat_health = key_health.clone();
        let heartbeat_gate = self.power.gate();
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

        let command_handle = match self.spawn_command_loop(&api_key, &session_token, &key_health) {
            Ok(handle) => handle,
            Err(e) => {
                error!("Failed to create command client: {}", e);
                return SessionEnd::Shutdown;
            }
        };

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
                self.power.gate(),
                self.startup_progress(),
            )
            .await;

        // Make sure the other loops stop too, then wait for them
        session_token.cancel();
        let _ = heartbeat_handle.await;
        if let Some(handle) = command_handle {
            let _ = handle.await;
        }
        let _ = update_handle.await;
        self.set_session_auth(None);

        session_end(
            key_health.key_rejected(),
            self.cancellation_token.is_cancelled(),
        )
    }

    /// Start polling for commands. Windows only: every other build is a
    /// read-only monitor and never asks the server for commands.
    #[cfg(windows)]
    fn spawn_command_loop(
        &self,
        api_key: &str,
        session_token: &CancellationToken,
        key_health: &Arc<KeyHealth>,
    ) -> Result<Option<tokio::task::JoinHandle<()>>> {
        let command_client = CommandClient::new(self.config.clone())?;
        let api_key = api_key.to_string();
        let token = session_token.clone();
        let health = key_health.clone();
        let power = self.power.clone();
        let startup = self.startup_progress();

        Ok(Some(tokio::spawn(async move {
            command_client
                .start_command_loop(api_key, token, health, power, startup)
                .await;
        })))
    }

    #[cfg(not(windows))]
    fn spawn_command_loop(
        &self,
        _api_key: &str,
        _session_token: &CancellationToken,
        _key_health: &Arc<KeyHealth>,
    ) -> Result<Option<tokio::task::JoinHandle<()>>> {
        info!("Monitor-only agent: command polling is not part of this build");
        // Nothing to drain, so the startup keep-awake need not wait for it.
        self.startup_progress().mark(Milestone::CommandsDrained);
        Ok(None)
    }

    fn set_session_auth(&self, auth: Option<SessionAuth>) {
        *self
            .session_auth
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner()) = auth;
    }

    /// Tell the server the machine booted, but only if it really did start
    /// recently: a service restart (self-update) on an always-on PC is not a
    /// power-on. Skipping still counts as the startup milestone.
    fn announce_boot_if_fresh(&self) {
        let uptime = std::time::Duration::from_secs(::sysinfo::System::uptime());
        let max_uptime = std::time::Duration::from_secs(self.config.boot_notice_max_uptime);

        if power_state::is_fresh_boot(uptime, max_uptime) {
            self.power
                .announce(PowerNotice::powering_on(PowerReason::Boot));
        } else {
            info!(
                "Machine up for {}s; agent restart, not announcing a boot",
                uptime.as_secs()
            );
            self.startup_progress().mark(Milestone::BootAnnounced);
        }
    }

    fn startup_progress(&self) -> StartupProgress {
        self.startup
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
            .clone()
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

                if notice == PowerNotice::powering_on(PowerReason::Boot) {
                    agent.startup_progress().mark(Milestone::BootAnnounced);
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
