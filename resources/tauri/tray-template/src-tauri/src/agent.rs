use anyhow::{Context, Result};
use std::sync::Arc;
use tokio::sync::RwLock;
use tokio_util::sync::CancellationToken;
use tracing::{debug, error, info, warn};

use crate::config::Config;
use crate::enrollment::{EnrollmentManager, EnrollmentStatus};
use crate::metrics::MetricsCollector;
use crate::storage::Storage;
use crate::sysinfo::SystemInfo;

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

/// Main RMM Agent
pub struct Agent {
    config: Config,
    system_info: SystemInfo,
    enrollment_manager: EnrollmentManager,
    state: Arc<RwLock<AgentState>>,
    cancellation_token: CancellationToken,
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

        Ok(Self {
            config,
            system_info,
            enrollment_manager,
            state: Arc::new(RwLock::new(initial_state)),
            cancellation_token: CancellationToken::new(),
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

    /// Get the cancellation token for graceful shutdown
    pub fn cancellation_token(&self) -> CancellationToken {
        self.cancellation_token.clone()
    }

    /// Start the agent (blocking - runs until cancelled)
    pub async fn run(self: Arc<Self>) -> Result<()> {
        info!("Starting RMM Agent");
        info!("Device: {}", self.system_info.hostname);
        info!("Fingerprint: {}", self.system_info.hardware_fingerprint);
        info!("Server URL: {}", self.config.base_url);

        // Check if already enrolled
        if let Some(api_key) = self.enrollment_manager.get_api_key().await? {
            info!("Device is already enrolled, starting metrics collection");
            self.set_state(AgentState::Active).await;
            self.run_metrics_loop(api_key).await;
        } else {
            // Need to enroll first
            info!("Device not enrolled, starting enrollment process");
            self.run_enrollment_then_metrics().await?;
        }

        Ok(())
    }

    /// Run enrollment process, then start metrics collection
    async fn run_enrollment_then_metrics(&self) -> Result<()> {
        info!("Enrolling device with backend");

        // Submit enrollment request
        self.enrollment_manager
            .enroll(&self.system_info)
            .await
            .context("Failed to enroll device")?;

        self.set_state(AgentState::PendingApproval).await;

        // Wait for approval
        info!("Waiting for approval from administrator...");
        match self
            .enrollment_manager
            .wait_for_approval(&self.system_info, self.cancellation_token.clone())
            .await
        {
            Ok(_) => {
                info!("Device approved!");
                self.set_state(AgentState::Active).await;

                // Get the API key and start metrics
                if let Some(api_key) = self.enrollment_manager.get_api_key().await? {
                    self.run_metrics_loop(api_key).await;
                } else {
                    let msg = "Device approved but no API key found".to_string();
                    error!("{}", msg);
                    self.set_state(AgentState::Error(msg)).await;
                }
            }
            Err(e) => {
                let msg = format!("Enrollment failed: {}", e);
                error!("{}", msg);
                self.set_state(AgentState::Error(msg)).await;
            }
        }

        Ok(())
    }

    /// Run the metrics collection loop (blocks until cancelled)
    async fn run_metrics_loop(&self, api_key: String) {
        info!("Starting metrics collection");

        let collector = match MetricsCollector::new(
            self.config.clone(),
            self.system_info.hostname.clone(),
        ) {
            Ok(c) => c,
            Err(e) => {
                error!("Failed to create metrics collector: {}", e);
                return;
            }
        };

        // Check if Netdata is available
        if !collector.check_netdata_available().await {
            warn!("Netdata is not available - metrics collection will be limited");
            warn!("Please ensure Netdata is installed and running");
        }

        // Start the metrics loop with cancellation support
        collector
            .start_metrics_loop(api_key, self.cancellation_token.clone())
            .await;
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
