use anyhow::{Context, Result};
use chrono::Utc;
use serde::{Deserialize, Serialize};
use std::time::Duration;
use tracing::{debug, error, info, warn};

use crate::config::Config;

/// Metrics payload sent to the backend
#[derive(Debug, Serialize)]
pub struct MetricsPayload {
    pub hostname: String,
    pub timestamp: String,
    pub cpu: Option<NetdataResponse>,
    pub ram: Option<NetdataResponse>,
}

/// Netdata API response structure
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NetdataResponse {
    pub labels: Vec<String>,
    pub data: Vec<Vec<f64>>,
}

/// Metrics collector and submitter
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

    /// Collect CPU metrics from Netdata
    async fn collect_cpu_metrics(&self) -> Result<NetdataResponse> {
        let url = format!(
            "{}/api/v1/data?chart=system.cpu&format=json&points=1",
            self.config.netdata_url
        );

        debug!("Collecting CPU metrics from Netdata: {}", url);

        let response = self
            .client
            .get(&url)
            .send()
            .await
            .context("Failed to fetch CPU metrics from Netdata")?;

        if !response.status().is_success() {
            let status = response.status();
            anyhow::bail!("Netdata CPU request failed with status {}", status);
        }

        let data: NetdataResponse = response
            .json()
            .await
            .context("Failed to parse CPU metrics response")?;

        debug!("CPU metrics collected: {} data points", data.data.len());
        Ok(data)
    }

    /// Collect RAM metrics from Netdata
    async fn collect_ram_metrics(&self) -> Result<NetdataResponse> {
        let url = format!(
            "{}/api/v1/data?chart=system.ram&format=json&points=1",
            self.config.netdata_url
        );

        debug!("Collecting RAM metrics from Netdata: {}", url);

        let response = self
            .client
            .get(&url)
            .send()
            .await
            .context("Failed to fetch RAM metrics from Netdata")?;

        if !response.status().is_success() {
            let status = response.status();
            anyhow::bail!("Netdata RAM request failed with status {}", status);
        }

        let data: NetdataResponse = response
            .json()
            .await
            .context("Failed to parse RAM metrics response")?;

        debug!("RAM metrics collected: {} data points", data.data.len());
        Ok(data)
    }

    /// Collect all metrics from Netdata
    pub async fn collect_metrics(&self) -> MetricsPayload {
        debug!("Collecting metrics from Netdata");

        let cpu = match self.collect_cpu_metrics().await {
            Ok(data) => Some(data),
            Err(e) => {
                warn!("Failed to collect CPU metrics: {}", e);
                None
            }
        };

        let ram = match self.collect_ram_metrics().await {
            Ok(data) => Some(data),
            Err(e) => {
                warn!("Failed to collect RAM metrics: {}", e);
                None
            }
        };

        MetricsPayload {
            hostname: self.hostname.clone(),
            timestamp: Utc::now().to_rfc3339(),
            cpu,
            ram,
        }
    }

    /// Submit metrics to the backend
    pub async fn submit_metrics(&self, metrics: &MetricsPayload, api_key: &str) -> Result<()> {
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

        if metrics.cpu.is_none() && metrics.ram.is_none() {
            warn!("No metrics collected (Netdata may be unavailable)");
            // Don't return an error, just skip this cycle
            return Ok(());
        }

        self.submit_metrics(&metrics, api_key).await?;
        info!("Metrics collected and submitted successfully");
        Ok(())
    }

    /// Check if Netdata is available
    pub async fn check_netdata_available(&self) -> bool {
        let url = format!("{}/api/v1/info", self.config.netdata_url);

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
    pub async fn start_metrics_loop(&self, api_key: String) {
        info!(
            "Starting metrics collection loop (interval: {}s)",
            self.config.metrics_interval
        );

        // Check if Netdata is available on startup
        if !self.check_netdata_available().await {
            warn!("Netdata is not available at startup - metrics will be limited");
        }

        loop {
            match self.collect_and_submit(&api_key).await {
                Ok(_) => {}
                Err(e) => {
                    error!("Error in metrics collection: {}", e);
                }
            }

            tokio::time::sleep(Duration::from_secs(self.config.metrics_interval)).await;
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn test_collect_metrics_when_netdata_unavailable() {
        let config = Config::default();
        let collector = MetricsCollector::new(config, "test-host".to_string()).unwrap();

        let metrics = collector.collect_metrics().await;
        assert_eq!(metrics.hostname, "test-host");
        assert!(!metrics.timestamp.is_empty());
        // CPU and RAM will be None if Netdata is not available
    }

    #[test]
    fn test_metrics_payload_serialization() {
        let payload = MetricsPayload {
            hostname: "test-host".to_string(),
            timestamp: "2025-12-05T10:00:00Z".to_string(),
            cpu: Some(NetdataResponse {
                labels: vec!["time".to_string(), "user".to_string()],
                data: vec![vec![1733392800.0, 10.0]],
            }),
            ram: None,
        };

        let json = serde_json::to_string(&payload).unwrap();
        assert!(json.contains("test-host"));
        assert!(json.contains("cpu"));
    }
}
