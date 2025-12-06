use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use std::time::Duration;
use tokio_util::sync::CancellationToken;
use tracing::{debug, info, warn};

use crate::config::Config;
use crate::storage::Storage;
use crate::sysinfo::SystemInfo;

/// Enrollment request payload
#[derive(Debug, Serialize)]
struct EnrollRequest {
    hostname: String,
    os: String,
    hardware_fingerprint: String,
    cpu_model: String,
    cpu_cores: usize,
    total_ram_bytes: u64,
}

/// Status check request
#[derive(Debug, Serialize)]
struct CheckRequest {
    hostname: String,
    hardware_fingerprint: String,
}

/// Status check response
#[derive(Debug, Deserialize)]
struct CheckResponse {
    status: String,
    api_key: Option<String>,
}

/// Enrollment manager
pub struct EnrollmentManager {
    config: Config,
    storage: Storage,
    client: reqwest::Client,
}

impl EnrollmentManager {
    /// Create a new enrollment manager
    pub fn new(config: Config, storage: Storage) -> Result<Self> {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(30))
            .build()
            .context("Failed to create HTTP client")?;

        Ok(Self {
            config,
            storage,
            client,
        })
    }

    /// Check if device is enrolled (has API key)
    pub async fn is_enrolled(&self) -> bool {
        self.storage.has_key().await
    }

    /// Get the stored API key
    pub async fn get_api_key(&self) -> Result<Option<String>> {
        if self.storage.has_key().await {
            Ok(Some(self.storage.read_key().await?))
        } else {
            Ok(None)
        }
    }

    /// Enroll the device with the backend
    pub async fn enroll(&self, system_info: &SystemInfo) -> Result<()> {
        info!("Enrolling device: {}", system_info.hostname);

        let url = format!("{}/api/enroll", self.config.base_url);
        let payload = EnrollRequest {
            hostname: system_info.hostname.clone(),
            os: format!("{} {}", system_info.os_name, system_info.os_version),
            hardware_fingerprint: system_info.hardware_fingerprint.clone(),
            cpu_model: system_info.cpu_model.clone(),
            cpu_cores: system_info.cpu_cores,
            total_ram_bytes: system_info.total_ram_bytes,
        };

        debug!("Sending enrollment request to {}", url);

        let response = self
            .client
            .post(&url)
            .json(&payload)
            .send()
            .await
            .context("Failed to send enrollment request")?;

        if response.status().is_success() {
            info!("Enrollment request submitted successfully");
            Ok(())
        } else {
            let status = response.status();
            let body = response.text().await.unwrap_or_default();
            warn!("Enrollment failed: {} - {}", status, body);
            anyhow::bail!("Enrollment failed with status {}: {}", status, body)
        }
    }

    /// Check enrollment status with the backend
    pub async fn check_status(&self, system_info: &SystemInfo) -> Result<EnrollmentStatus> {
        debug!("Checking enrollment status");

        let url = format!("{}/api/check", self.config.base_url);
        let payload = CheckRequest {
            hostname: system_info.hostname.clone(),
            hardware_fingerprint: system_info.hardware_fingerprint.clone(),
        };

        let response = self
            .client
            .post(&url)
            .json(&payload)
            .send()
            .await
            .context("Failed to send status check request")?;

        if !response.status().is_success() {
            let status = response.status();
            let body = response.text().await.unwrap_or_default();
            warn!("Status check failed: {} - {}", status, body);
            anyhow::bail!("Status check failed with status {}: {}", status, body)
        }

        let check_response: CheckResponse = response
            .json()
            .await
            .context("Failed to parse status check response")?;

        debug!("Status check response: {:?}", check_response);

        match check_response.status.as_str() {
            "approved" => {
                if let Some(api_key) = check_response.api_key {
                    info!("Device approved! Saving API key");
                    self.storage.save_key(&api_key).await?;
                    Ok(EnrollmentStatus::Approved)
                } else {
                    warn!("Device approved but no API key provided");
                    Ok(EnrollmentStatus::Pending)
                }
            }
            "pending" => {
                debug!("Device still pending approval");
                Ok(EnrollmentStatus::Pending)
            }
            "revoked" => {
                warn!("Device has been revoked");
                // Delete any existing key
                let _ = self.storage.delete_key().await;
                Ok(EnrollmentStatus::Revoked)
            }
            status => {
                warn!("Unknown enrollment status: {}", status);
                Ok(EnrollmentStatus::Unknown(status.to_string()))
            }
        }
    }

    /// Wait for approval by polling the backend with graceful shutdown support
    pub async fn wait_for_approval(
        &self,
        system_info: &SystemInfo,
        cancellation_token: CancellationToken,
    ) -> Result<()> {
        info!("Waiting for device approval...");

        loop {
            tokio::select! {
                // Wait for cancellation signal
                _ = cancellation_token.cancelled() => {
                    info!("Enrollment polling cancelled - shutting down gracefully");
                    anyhow::bail!("Enrollment cancelled by shutdown signal");
                }
                // Wait for the poll interval to elapse
                _ = tokio::time::sleep(Duration::from_secs(self.config.enrollment_poll_interval)) => {
                    match self.check_status(system_info).await {
                        Ok(EnrollmentStatus::Approved) => {
                            info!("Device approved!");
                            return Ok(());
                        }
                        Ok(EnrollmentStatus::Pending) => {
                            debug!(
                                "Still pending, waiting {} seconds...",
                                self.config.enrollment_poll_interval
                            );
                        }
                        Ok(EnrollmentStatus::Revoked) => {
                            anyhow::bail!("Device was revoked during enrollment");
                        }
                        Ok(EnrollmentStatus::Unknown(status)) => {
                            warn!("Unknown status '{}', continuing to wait...", status);
                        }
                        Err(e) => {
                            warn!("Error checking status: {}", e);
                        }
                    }
                }
            }
        }
    }
}

/// Enrollment status
#[derive(Debug, Clone, PartialEq)]
pub enum EnrollmentStatus {
    /// Device is approved and has an API key
    Approved,
    /// Device is pending approval
    Pending,
    /// Device has been revoked
    Revoked,
    /// Unknown status
    Unknown(String),
}

impl EnrollmentStatus {
    /// Get a display string for the status
    pub fn as_str(&self) -> &str {
        match self {
            EnrollmentStatus::Approved => "Approved",
            EnrollmentStatus::Pending => "Pending",
            EnrollmentStatus::Revoked => "Revoked",
            EnrollmentStatus::Unknown(_) => "Unknown",
        }
    }
}
