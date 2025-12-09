//! Simplified metrics collector - forwards raw Netdata JSON to Laravel
//!
//! The agent's job is simple:
//! 1. Fetch raw JSON from Netdata v3 API
//! 2. Forward it to Laravel
//! 3. Let Laravel handle all parsing

use anyhow::{Context, Result};
use chrono::Utc;
use serde::Serialize;
use std::time::Duration;
use tokio_util::sync::CancellationToken;
use tracing::{debug, error, info, warn};

use crate::config::Config;

// ============================================================================
// Simple Payload Structure (sent to Laravel)
// ============================================================================

/// Raw metrics payload - forwards Netdata JSON directly to Laravel
#[derive(Debug, Serialize)]
pub struct RawMetricsPayload {
    /// Device hostname
    pub hostname: String,
    /// Timestamp of collection
    pub timestamp: String,
    /// Agent version
    pub agent_version: String,
    /// Raw Netdata /api/v3/info response (optional)
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_info: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for CPU metrics
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_cpu: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for RAM metrics
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_ram: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for load metrics
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_load: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for uptime metrics
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_uptime: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for disk space metrics
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_disk: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for network metrics
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_net: Option<serde_json::Value>,
}

// ============================================================================
// Metrics Collector
// ============================================================================

/// Simple metrics collector - fetches from Netdata and forwards to Laravel
pub struct MetricsCollector {
    config: Config,
    client: reqwest::Client,
    hostname: String,
}

impl MetricsCollector {
    /// Create a new metrics collector
    pub fn new(config: Config, hostname: String) -> Result<Self> {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(10))
            .build()
            .context("Failed to create HTTP client")?;

        Ok(Self {
            config,
            client,
            hostname,
        })
    }

    /// Fetch raw JSON from Netdata v3 API (no parsing)
    async fn fetch_netdata_info(&self) -> Option<serde_json::Value> {
        let url = format!("{}/api/v3/info", self.config.netdata_url);
        debug!("Fetching Netdata info from: {}", url);

        match self.client.get(&url).send().await {
            Ok(response) if response.status().is_success() => {
                response.json().await.ok()
            }
            Ok(response) => {
                debug!("Netdata info request failed: {}", response.status());
                None
            }
            Err(e) => {
                debug!("Netdata info request error: {}", e);
                None
            }
        }
    }

    /// Fetch raw data from a Netdata v3 API context (no parsing)
    async fn fetch_netdata_context(&self, context: &str) -> Option<serde_json::Value> {
        let url = format!(
            "{}/api/v3/data?contexts={}&format=json&points=1&time_group=average",
            self.config.netdata_url, context
        );
        debug!("Fetching Netdata {} from: {}", context, url);

        match self.client.get(&url).send().await {
            Ok(response) if response.status().is_success() => {
                response.json().await.ok()
            }
            Ok(response) => {
                debug!("Netdata {} request failed: {}", context, response.status());
                None
            }
            Err(e) => {
                debug!("Netdata {} request error: {}", context, e);
                None
            }
        }
    }

    /// Collect raw metrics from Netdata
    pub async fn collect_metrics(&self) -> RawMetricsPayload {
        debug!("Collecting raw metrics from Netdata");

        // Fetch all contexts in parallel
        let (netdata_info, netdata_cpu, netdata_ram, netdata_load, netdata_uptime, netdata_disk, netdata_net) = tokio::join!(
            self.fetch_netdata_info(),
            self.fetch_netdata_context("system.cpu"),
            self.fetch_netdata_context("system.ram"),
            self.fetch_netdata_context("system.load"),
            self.fetch_netdata_context("system.uptime"),
            self.fetch_netdata_context("disk.space"),
            self.fetch_netdata_context("system.net"),
        );

        RawMetricsPayload {
            hostname: self.hostname.clone(),
            timestamp: Utc::now().to_rfc3339(),
            agent_version: env!("CARGO_PKG_VERSION").to_string(),
            netdata_info,
            netdata_cpu,
            netdata_ram,
            netdata_load,
            netdata_uptime,
            netdata_disk,
            netdata_net,
        }
    }

    /// Submit raw metrics to Laravel backend
    pub async fn submit_metrics(&self, metrics: &RawMetricsPayload, api_key: &str) -> Result<()> {
        let url = format!("{}/api/metrics", self.config.base_url);

        debug!("Submitting metrics to backend: {}", url);

        let response = self
            .client
            .post(&url)
            .header("X-Agent-Key", api_key)
            .json(metrics)
            .send()
            .await
            .context("Failed to submit metrics to backend")?;

        if !response.status().is_success() {
            let status = response.status();
            let body = response.text().await.unwrap_or_default();
            warn!("Metrics submission failed: {} - {}", status, body);
            anyhow::bail!("Metrics submission failed with status {}: {}", status, body)
        }

        debug!("Metrics submitted successfully");
        Ok(())
    }

    /// Collect and submit metrics in one operation
    pub async fn collect_and_submit(&self, api_key: &str) -> Result<()> {
        let metrics = self.collect_metrics().await;

        match self.submit_metrics(&metrics, api_key).await {
            Ok(_) => {
                if metrics.netdata_cpu.is_some() || metrics.netdata_ram.is_some() {
                    info!("Metrics submitted (raw Netdata data)");
                } else {
                    warn!("Metrics submitted with no Netdata data (Netdata may be unavailable)");
                }
                Ok(())
            }
            Err(e) => {
                warn!("Failed to submit metrics: {}", e);
                Ok(()) // Don't propagate - retry next interval
            }
        }
    }

    /// Check if Netdata is available
    pub async fn check_netdata_available(&self) -> bool {
        let url = format!("{}/api/v3/info", self.config.netdata_url);

        match self.client.get(&url).send().await {
            Ok(response) if response.status().is_success() => {
                debug!("Netdata is available");
                true
            }
            Ok(response) => {
                warn!("Netdata returned status: {}", response.status());
                false
            }
            Err(e) => {
                debug!("Netdata is not available: {}", e);
                false
            }
        }
    }

    /// Start metrics collection loop
    pub async fn start_metrics_loop(&self, api_key: String, cancellation_token: CancellationToken) {
        info!(
            "Starting metrics collection loop (interval: {}s)",
            self.config.metrics_interval
        );

        if !self.check_netdata_available().await {
            warn!("Netdata is not available at startup - metrics will be limited");
        }

        loop {
            tokio::select! {
                _ = cancellation_token.cancelled() => {
                    info!("Metrics collection loop cancelled");
                    break;
                }
                _ = tokio::time::sleep(Duration::from_secs(self.config.metrics_interval)) => {
                    if let Err(e) = self.collect_and_submit(&api_key).await {
                        error!("Error in metrics collection: {}", e);
                    }
                }
            }
        }

        info!("Metrics collection loop stopped");
    }

    /// Send a lightweight heartbeat to the backend
    pub async fn send_heartbeat(&self, api_key: &str) -> Result<()> {
        let url = format!("{}/api/heartbeat", self.config.base_url);

        debug!("Sending heartbeat to: {}", url);

        let response = self
            .client
            .post(&url)
            .header("X-Agent-Key", api_key)
            .send()
            .await;

        match response {
            Ok(resp) => {
                let status = resp.status();

                if status.is_success() {
                    debug!("Heartbeat OK");
                    Ok(())
                } else if status == reqwest::StatusCode::UNAUTHORIZED {
                    let body = resp.text().await.unwrap_or_default();
                    warn!("Heartbeat auth failed (401): {}", body);
                    anyhow::bail!("Authentication failed: {}", body)
                } else if status == reqwest::StatusCode::TOO_MANY_REQUESTS {
                    warn!("Heartbeat rate limited (429)");
                    Ok(())
                } else {
                    let body = resp.text().await.unwrap_or_default();
                    warn!("Heartbeat failed ({}): {}", status.as_u16(), body);
                    Ok(())
                }
            }
            Err(e) => {
                warn!("Heartbeat network error: {}", e);
                Ok(())
            }
        }
    }

    /// Start heartbeat loop
    pub async fn start_heartbeat_loop(&self, api_key: String, cancellation_token: CancellationToken) {
        info!(
            "Starting heartbeat loop (interval: {}s)",
            self.config.heartbeat_interval
        );

        loop {
            tokio::select! {
                _ = cancellation_token.cancelled() => {
                    info!("Heartbeat loop cancelled");
                    break;
                }
                _ = tokio::time::sleep(Duration::from_secs(self.config.heartbeat_interval)) => {
                    if let Err(e) = self.send_heartbeat(&api_key).await {
                        error!("Heartbeat error: {}", e);
                    }
                }
            }
        }

        info!("Heartbeat loop stopped");
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_raw_payload_serialization() {
        let payload = RawMetricsPayload {
            hostname: "test-host".to_string(),
            timestamp: "2025-12-09T10:00:00Z".to_string(),
            agent_version: "0.3.0".to_string(),
            netdata_info: None,
            netdata_cpu: Some(serde_json::json!({
                "view": {
                    "dimensions": {
                        "ids": ["user", "system"],
                        "sts": {
                            "avg": [10.5, 5.2]
                        }
                    }
                }
            })),
            netdata_ram: None,
            netdata_load: None,
            netdata_uptime: None,
            netdata_disk: None,
            netdata_net: None,
        };

        let json = serde_json::to_string(&payload).unwrap();
        assert!(json.contains("test-host"));
        assert!(json.contains("netdata_cpu"));
        assert!(json.contains("10.5"));
    }
}
