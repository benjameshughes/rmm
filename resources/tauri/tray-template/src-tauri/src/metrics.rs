use anyhow::{Context, Result};
use chrono::Utc;
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use std::time::Duration;
use tokio_util::sync::CancellationToken;
use tracing::{debug, error, info, warn};

use crate::config::Config;

// ============================================================================
// Netdata v3 API Response Structures
// ============================================================================

/// Netdata v3 /api/v3/info response
#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct NetdataInfo {
    #[serde(default)]
    pub version: Option<String>,
    #[serde(default)]
    pub uid: Option<String>,
    #[serde(default)]
    pub os_name: Option<String>,
    #[serde(default)]
    pub os_id: Option<String>,
    #[serde(default)]
    pub os_version: Option<String>,
    #[serde(default)]
    pub kernel_name: Option<String>,
    #[serde(default)]
    pub kernel_version: Option<String>,
    #[serde(default)]
    pub architecture: Option<String>,
    #[serde(default)]
    pub virtualization: Option<String>,
    #[serde(default)]
    pub container: Option<String>,
    #[serde(default)]
    pub is_k8s_node: Option<bool>,
    #[serde(default)]
    pub alarms: Option<NetdataAlarms>,
    #[serde(default)]
    pub labels: Option<HashMap<String, String>>,
}

/// Netdata alarms summary
#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct NetdataAlarms {
    #[serde(default)]
    pub normal: i32,
    #[serde(default)]
    pub warning: i32,
    #[serde(default)]
    pub critical: i32,
}

/// Netdata v3 /api/v3/data response wrapper (jsonwrap2 format)
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NetdataDataResponse {
    #[serde(default)]
    pub api: Option<i32>,
    #[serde(default)]
    pub view: Option<NetdataView>,
    #[serde(default)]
    pub result: Option<NetdataResult>,
    #[serde(default)]
    pub db: Option<NetdataDb>,
}

/// View metadata from v3 response
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NetdataView {
    #[serde(default)]
    pub title: Option<String>,
    #[serde(default)]
    pub units: Option<serde_json::Value>, // Can be string or array
    #[serde(default)]
    pub after: Option<i64>,
    #[serde(default)]
    pub before: Option<i64>,
    #[serde(default)]
    pub min: Option<f64>,
    #[serde(default)]
    pub max: Option<f64>,
    #[serde(default)]
    pub dimensions: Option<NetdataDimensions>,
}

/// Dimensions info from v3 response
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NetdataDimensions {
    #[serde(default)]
    pub ids: Vec<String>,
    #[serde(default)]
    pub names: Vec<String>,
    #[serde(default)]
    pub units: Option<Vec<String>>,
}

/// Database info from v3 response
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NetdataDb {
    #[serde(default)]
    pub units: Option<serde_json::Value>,
    #[serde(default)]
    pub update_every: Option<i32>,
}

/// Result data structure - handles multiple formats
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(untagged)]
pub enum NetdataResult {
    /// Array format: [[timestamp, val1, val2, ...], ...]
    Array(Vec<Vec<f64>>),
    /// Object format with labels and data
    Object {
        labels: Vec<String>,
        data: Vec<Vec<f64>>,
    },
}

// ============================================================================
// Metrics Payload Structure (sent to Laravel)
// ============================================================================

/// Complete metrics payload sent to the backend
#[derive(Debug, Serialize)]
pub struct MetricsPayload {
    pub hostname: String,
    pub timestamp: String,
    pub agent_version: String,

    // System info from Netdata
    #[serde(skip_serializing_if = "Option::is_none")]
    pub system_info: Option<SystemInfo>,

    // Core metrics
    #[serde(skip_serializing_if = "Option::is_none")]
    pub cpu: Option<CpuMetrics>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub memory: Option<MemoryMetrics>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub load: Option<LoadMetrics>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub uptime: Option<UptimeMetrics>,

    // Extended metrics
    #[serde(skip_serializing_if = "Option::is_none")]
    pub disks: Option<Vec<DiskMetrics>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub network: Option<Vec<NetworkMetrics>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub processes: Option<ProcessMetrics>,

    // Alerts summary
    #[serde(skip_serializing_if = "Option::is_none")]
    pub alerts: Option<AlertsSummary>,

    // Raw netdata response for backward compatibility
    #[serde(skip_serializing_if = "Option::is_none")]
    pub raw_cpu: Option<NetdataDataResponse>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub raw_ram: Option<NetdataDataResponse>,
}

/// System information collected from Netdata
#[derive(Debug, Clone, Serialize)]
pub struct SystemInfo {
    pub netdata_version: Option<String>,
    pub os_name: Option<String>,
    pub os_version: Option<String>,
    pub kernel_name: Option<String>,
    pub kernel_version: Option<String>,
    pub architecture: Option<String>,
    pub virtualization: Option<String>,
    pub container: Option<String>,
    pub is_k8s_node: bool,
}

/// CPU metrics - parsed and calculated
#[derive(Debug, Clone, Serialize)]
pub struct CpuMetrics {
    /// Total CPU usage percentage (0-100)
    pub usage_percent: f64,
    /// Individual CPU states
    pub user: Option<f64>,
    pub system: Option<f64>,
    pub nice: Option<f64>,
    pub iowait: Option<f64>,
    pub irq: Option<f64>,
    pub softirq: Option<f64>,
    pub steal: Option<f64>,
    pub idle: Option<f64>,
}

/// Memory metrics - parsed and calculated
#[derive(Debug, Clone, Serialize)]
pub struct MemoryMetrics {
    /// RAM usage percentage (0-100)
    pub usage_percent: f64,
    /// Memory in MiB
    pub used_mib: Option<f64>,
    pub free_mib: Option<f64>,
    pub cached_mib: Option<f64>,
    pub buffers_mib: Option<f64>,
    pub available_mib: Option<f64>,
    pub total_mib: Option<f64>,
}

/// Load average metrics
#[derive(Debug, Clone, Serialize)]
pub struct LoadMetrics {
    pub load1: f64,
    pub load5: f64,
    pub load15: f64,
}

/// System uptime
#[derive(Debug, Clone, Serialize)]
pub struct UptimeMetrics {
    /// Uptime in seconds
    pub seconds: f64,
}

/// Per-disk metrics
#[derive(Debug, Clone, Serialize)]
pub struct DiskMetrics {
    pub name: String,
    pub read_kbps: Option<f64>,
    pub write_kbps: Option<f64>,
    pub utilization_percent: Option<f64>,
}

/// Per-interface network metrics
#[derive(Debug, Clone, Serialize)]
pub struct NetworkMetrics {
    pub interface: String,
    pub received_kbps: Option<f64>,
    pub sent_kbps: Option<f64>,
}

/// Process metrics
#[derive(Debug, Clone, Serialize)]
pub struct ProcessMetrics {
    pub running: Option<i32>,
    pub blocked: Option<i32>,
    pub total: Option<i32>,
}

/// Alerts summary
#[derive(Debug, Clone, Serialize)]
pub struct AlertsSummary {
    pub normal: i32,
    pub warning: i32,
    pub critical: i32,
}

// ============================================================================
// Legacy support - for backward compatibility
// ============================================================================

/// Legacy Netdata API response structure (v1 format)
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NetdataResponse {
    pub labels: Vec<String>,
    pub data: Vec<Vec<f64>>,
}

// ============================================================================
// Metrics Collector
// ============================================================================

/// Metrics collector and submitter using Netdata v3 API
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

    /// Fetch system info from Netdata v3 API
    async fn fetch_system_info(&self) -> Result<NetdataInfo> {
        let url = format!("{}/api/v3/info", self.config.netdata_url);
        debug!("Fetching system info from: {}", url);

        let response = self
            .client
            .get(&url)
            .send()
            .await
            .context("Failed to fetch system info")?;

        if !response.status().is_success() {
            anyhow::bail!("System info request failed: {}", response.status());
        }

        response
            .json()
            .await
            .context("Failed to parse system info")
    }

    /// Fetch data for a specific context using v3 API
    async fn fetch_context_data(&self, context: &str) -> Result<NetdataDataResponse> {
        let url = format!(
            "{}/api/v3/data?contexts={}&format=json&options=jsonwrap&points=1&time_group=average",
            self.config.netdata_url, context
        );
        debug!("Fetching context data from: {}", url);

        let response = self
            .client
            .get(&url)
            .send()
            .await
            .with_context(|| format!("Failed to fetch {} data", context))?;

        if !response.status().is_success() {
            anyhow::bail!("{} request failed: {}", context, response.status());
        }

        response
            .json()
            .await
            .with_context(|| format!("Failed to parse {} response", context))
    }

    /// Parse CPU metrics from v3 response
    fn parse_cpu_metrics(&self, data: &NetdataDataResponse) -> Option<CpuMetrics> {
        let view = data.view.as_ref()?;
        let dims = view.dimensions.as_ref()?;
        let result = data.result.as_ref()?;

        let values = match result {
            NetdataResult::Array(arr) => arr.last()?,
            NetdataResult::Object { data, .. } => data.last()?,
        };

        // Skip first value (timestamp) if present
        let offset = if dims.ids.len() < values.len() { 1 } else { 0 };

        let mut cpu = CpuMetrics {
            usage_percent: 0.0,
            user: None,
            system: None,
            nice: None,
            iowait: None,
            irq: None,
            softirq: None,
            steal: None,
            idle: None,
        };

        for (i, name) in dims.ids.iter().enumerate() {
            let val = values.get(i + offset).copied();
            match name.as_str() {
                "user" => cpu.user = val,
                "system" => cpu.system = val,
                "nice" => cpu.nice = val,
                "iowait" => cpu.iowait = val,
                "irq" => cpu.irq = val,
                "softirq" => cpu.softirq = val,
                "steal" => cpu.steal = val,
                "idle" => cpu.idle = val,
                _ => {}
            }
        }

        // Calculate usage: 100 - idle
        if let Some(idle) = cpu.idle {
            cpu.usage_percent = (100.0 - idle).max(0.0).min(100.0);
        }

        Some(cpu)
    }

    /// Parse memory metrics from v3 response
    fn parse_memory_metrics(&self, data: &NetdataDataResponse) -> Option<MemoryMetrics> {
        let view = data.view.as_ref()?;
        let dims = view.dimensions.as_ref()?;
        let result = data.result.as_ref()?;

        let values = match result {
            NetdataResult::Array(arr) => arr.last()?,
            NetdataResult::Object { data, .. } => data.last()?,
        };

        let offset = if dims.ids.len() < values.len() { 1 } else { 0 };

        let mut mem = MemoryMetrics {
            usage_percent: 0.0,
            used_mib: None,
            free_mib: None,
            cached_mib: None,
            buffers_mib: None,
            available_mib: None,
            total_mib: None,
        };

        for (i, name) in dims.ids.iter().enumerate() {
            let val = values.get(i + offset).copied();
            match name.as_str() {
                "used" => mem.used_mib = val,
                "free" => mem.free_mib = val,
                "cached" => mem.cached_mib = val,
                "buffers" => mem.buffers_mib = val,
                "available" => mem.available_mib = val,
                _ => {}
            }
        }

        // Calculate total and usage
        let used = mem.used_mib.unwrap_or(0.0);
        let free = mem.free_mib.unwrap_or(0.0);
        let cached = mem.cached_mib.unwrap_or(0.0);
        let buffers = mem.buffers_mib.unwrap_or(0.0);
        let total = used + free + cached + buffers;

        if total > 0.0 {
            mem.total_mib = Some(total);
            mem.usage_percent = ((used / total) * 100.0).max(0.0).min(100.0);
        }

        Some(mem)
    }

    /// Parse load metrics from v3 response
    fn parse_load_metrics(&self, data: &NetdataDataResponse) -> Option<LoadMetrics> {
        let view = data.view.as_ref()?;
        let dims = view.dimensions.as_ref()?;
        let result = data.result.as_ref()?;

        let values = match result {
            NetdataResult::Array(arr) => arr.last()?,
            NetdataResult::Object { data, .. } => data.last()?,
        };

        let offset = if dims.ids.len() < values.len() { 1 } else { 0 };

        let mut load1 = 0.0;
        let mut load5 = 0.0;
        let mut load15 = 0.0;

        for (i, name) in dims.ids.iter().enumerate() {
            if let Some(&val) = values.get(i + offset) {
                match name.as_str() {
                    "load1" => load1 = val,
                    "load5" => load5 = val,
                    "load15" => load15 = val,
                    _ => {}
                }
            }
        }

        Some(LoadMetrics { load1, load5, load15 })
    }

    /// Parse uptime from v3 response
    fn parse_uptime(&self, data: &NetdataDataResponse) -> Option<UptimeMetrics> {
        let result = data.result.as_ref()?;

        let values = match result {
            NetdataResult::Array(arr) => arr.last()?,
            NetdataResult::Object { data, .. } => data.last()?,
        };

        // Uptime is usually the second value (after timestamp)
        let seconds = values.get(1).copied().unwrap_or(0.0);

        Some(UptimeMetrics { seconds })
    }

    /// Collect all metrics from Netdata v3 API
    pub async fn collect_metrics(&self) -> MetricsPayload {
        debug!("Collecting metrics from Netdata v3 API");

        let mut payload = MetricsPayload {
            hostname: self.hostname.clone(),
            timestamp: Utc::now().to_rfc3339(),
            agent_version: env!("CARGO_PKG_VERSION").to_string(),
            system_info: None,
            cpu: None,
            memory: None,
            load: None,
            uptime: None,
            disks: None,
            network: None,
            processes: None,
            alerts: None,
            raw_cpu: None,
            raw_ram: None,
        };

        // Fetch system info
        match self.fetch_system_info().await {
            Ok(info) => {
                payload.alerts = info.alarms.as_ref().map(|a| AlertsSummary {
                    normal: a.normal,
                    warning: a.warning,
                    critical: a.critical,
                });

                payload.system_info = Some(SystemInfo {
                    netdata_version: info.version,
                    os_name: info.os_name,
                    os_version: info.os_version,
                    kernel_name: info.kernel_name,
                    kernel_version: info.kernel_version,
                    architecture: info.architecture,
                    virtualization: info.virtualization,
                    container: info.container,
                    is_k8s_node: info.is_k8s_node.unwrap_or(false),
                });
            }
            Err(e) => warn!("Failed to fetch system info: {}", e),
        }

        // Fetch CPU metrics
        match self.fetch_context_data("system.cpu").await {
            Ok(data) => {
                payload.cpu = self.parse_cpu_metrics(&data);
                payload.raw_cpu = Some(data);
            }
            Err(e) => warn!("Failed to collect CPU metrics: {}", e),
        }

        // Fetch memory metrics
        match self.fetch_context_data("system.ram").await {
            Ok(data) => {
                payload.memory = self.parse_memory_metrics(&data);
                payload.raw_ram = Some(data);
            }
            Err(e) => warn!("Failed to collect memory metrics: {}", e),
        }

        // Fetch load average
        match self.fetch_context_data("system.load").await {
            Ok(data) => {
                payload.load = self.parse_load_metrics(&data);
            }
            Err(e) => debug!("Failed to collect load metrics: {}", e),
        }

        // Fetch uptime
        match self.fetch_context_data("system.uptime").await {
            Ok(data) => {
                payload.uptime = self.parse_uptime(&data);
            }
            Err(e) => debug!("Failed to collect uptime: {}", e),
        }

        payload
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
        // Always collect metrics - even if Netdata is down, we send what we can
        let metrics = self.collect_metrics().await;

        // Submit whatever metrics we have (even if Netdata is unavailable)
        // The payload will have hostname and timestamp at minimum
        match self.submit_metrics(&metrics, api_key).await {
            Ok(_) => {
                if metrics.cpu.is_some() || metrics.memory.is_some() {
                    info!(
                        "Metrics submitted: CPU={:.1}%, RAM={:.1}%",
                        metrics.cpu.as_ref().map(|c| c.usage_percent).unwrap_or(0.0),
                        metrics.memory.as_ref().map(|m| m.usage_percent).unwrap_or(0.0)
                    );
                } else {
                    warn!("Metrics submitted with no Netdata data (Netdata may be unavailable)");
                }
                Ok(())
            }
            Err(e) => {
                warn!("Failed to submit metrics to backend: {}", e);
                // Don't propagate the error - just log and continue
                // The metrics loop will retry on the next interval
                Ok(())
            }
        }
    }

    /// Check if Netdata is available
    pub async fn check_netdata_available(&self) -> bool {
        let url = format!("{}/api/v3/info", self.config.netdata_url);

        match self.client.get(&url).send().await {
            Ok(response) if response.status().is_success() => {
                debug!("Netdata is available (v3 API)");
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

    /// Start metrics collection loop with graceful shutdown support
    pub async fn start_metrics_loop(&self, api_key: String, cancellation_token: CancellationToken) {
        info!(
            "Starting metrics collection loop (interval: {}s, using v3 API)",
            self.config.metrics_interval
        );

        if !self.check_netdata_available().await {
            warn!("Netdata is not available at startup - metrics will be limited");
        }

        loop {
            tokio::select! {
                _ = cancellation_token.cancelled() => {
                    info!("Metrics collection loop cancelled - shutting down gracefully");
                    break;
                }
                _ = tokio::time::sleep(Duration::from_secs(self.config.metrics_interval)) => {
                    match self.collect_and_submit(&api_key).await {
                        Ok(_) => {}
                        Err(e) => {
                            error!("Error in metrics collection: {}", e);
                        }
                    }
                }
            }
        }

        info!("Metrics collection loop stopped");
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_metrics_payload_serialization() {
        let payload = MetricsPayload {
            hostname: "test-host".to_string(),
            timestamp: "2025-12-08T10:00:00Z".to_string(),
            agent_version: "0.2.0".to_string(),
            system_info: Some(SystemInfo {
                netdata_version: Some("1.44.0".to_string()),
                os_name: Some("Windows".to_string()),
                os_version: Some("10".to_string()),
                kernel_name: None,
                kernel_version: None,
                architecture: Some("x86_64".to_string()),
                virtualization: None,
                container: None,
                is_k8s_node: false,
            }),
            cpu: Some(CpuMetrics {
                usage_percent: 25.5,
                user: Some(15.0),
                system: Some(10.0),
                nice: Some(0.0),
                iowait: Some(0.5),
                irq: None,
                softirq: None,
                steal: None,
                idle: Some(74.5),
            }),
            memory: Some(MemoryMetrics {
                usage_percent: 65.0,
                used_mib: Some(8192.0),
                free_mib: Some(2048.0),
                cached_mib: Some(1024.0),
                buffers_mib: Some(512.0),
                available_mib: Some(4096.0),
                total_mib: Some(16384.0),
            }),
            load: Some(LoadMetrics {
                load1: 1.5,
                load5: 1.2,
                load15: 0.8,
            }),
            uptime: Some(UptimeMetrics { seconds: 86400.0 }),
            disks: None,
            network: None,
            processes: None,
            alerts: Some(AlertsSummary {
                normal: 10,
                warning: 2,
                critical: 0,
            }),
            raw_cpu: None,
            raw_ram: None,
        };

        let json = serde_json::to_string_pretty(&payload).unwrap();
        assert!(json.contains("test-host"));
        assert!(json.contains("usage_percent"));
        assert!(json.contains("25.5"));
    }

    #[test]
    fn test_cpu_metrics_defaults() {
        let cpu = CpuMetrics {
            usage_percent: 0.0,
            user: None,
            system: None,
            nice: None,
            iowait: None,
            irq: None,
            softirq: None,
            steal: None,
            idle: None,
        };
        assert_eq!(cpu.usage_percent, 0.0);
    }
}
