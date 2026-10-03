//! Simplified metrics collector - forwards raw Netdata JSON to Laravel
//!
//! The agent's job is simple:
//! 1. Fetch raw JSON from Netdata v3 API
//! 2. Forward it to Laravel
//! 3. Let Laravel handle all parsing

use anyhow::{Context, Result};
use chrono::Utc;
use serde::Serialize;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};
use tokio_util::sync::CancellationToken;
use tracing::{debug, error, info, warn};

use crate::config::{Config, NETDATA_GROUP_BY_INSTANCE};
use crate::power::PowerGate;

// ============================================================================
// API key health (dead-key detection)
// ============================================================================

/// Minimum consecutive 401 responses before the key is considered dead.
pub const DEAD_KEY_MIN_FAILURES: u32 = 6;
/// Minimum time the 401 streak must span before the key is considered dead.
pub const DEAD_KEY_MIN_DURATION: Duration = Duration::from_secs(180);

/// Outcome of an authenticated request, as far as key health is concerned.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum RequestOutcome {
    /// 2xx - the key was accepted.
    Success,
    /// 401 - the key was rejected.
    Unauthorized,
    /// Anything else (429, 5xx, other 4xx, timeouts, network errors).
    /// Says nothing about the key.
    Inconclusive,
}

impl RequestOutcome {
    /// Classify an HTTP status code.
    pub fn from_status(status: reqwest::StatusCode) -> Self {
        if status.is_success() {
            RequestOutcome::Success
        } else if status == reqwest::StatusCode::UNAUTHORIZED {
            RequestOutcome::Unauthorized
        } else {
            RequestOutcome::Inconclusive
        }
    }
}

/// Counts consecutive 401s across heartbeat and metrics submissions. The key
/// is declared dead only after at least `min_failures` consecutive 401s that
/// span at least `min_duration`, with no successful request in between.
#[derive(Debug)]
pub struct AuthFailureTracker {
    min_failures: u32,
    min_duration: Duration,
    consecutive: u32,
    streak_started: Option<Instant>,
}

impl AuthFailureTracker {
    pub fn new(min_failures: u32, min_duration: Duration) -> Self {
        Self {
            min_failures,
            min_duration,
            consecutive: 0,
            streak_started: None,
        }
    }

    /// Record an outcome observed at `now`. Returns true when the key should
    /// be considered dead.
    pub fn record(&mut self, outcome: RequestOutcome, now: Instant) -> bool {
        match outcome {
            RequestOutcome::Success => {
                self.consecutive = 0;
                self.streak_started = None;
                false
            }
            RequestOutcome::Unauthorized => {
                self.consecutive = self.consecutive.saturating_add(1);
                let started = *self.streak_started.get_or_insert(now);
                self.consecutive >= self.min_failures
                    && now.saturating_duration_since(started) >= self.min_duration
            }
            RequestOutcome::Inconclusive => false,
        }
    }

    pub fn consecutive_failures(&self) -> u32 {
        self.consecutive
    }
}

/// Shared between the heartbeat and metrics loops of one session. When the key
/// is declared dead the session token is cancelled so both loops stop and the
/// agent can return to enrollment.
pub struct KeyHealth {
    tracker: Mutex<AuthFailureTracker>,
    rejected: AtomicBool,
    session: CancellationToken,
}

impl KeyHealth {
    pub fn new(session: CancellationToken) -> Self {
        Self {
            tracker: Mutex::new(AuthFailureTracker::new(
                DEAD_KEY_MIN_FAILURES,
                DEAD_KEY_MIN_DURATION,
            )),
            rejected: AtomicBool::new(false),
            session,
        }
    }

    /// Record an outcome; cancels the session if the key is now dead.
    pub fn record(&self, outcome: RequestOutcome) {
        self.record_at(outcome, Instant::now());
    }

    fn record_at(&self, outcome: RequestOutcome, now: Instant) {
        let (dead, count) = {
            let mut tracker = self
                .tracker
                .lock()
                .unwrap_or_else(|poisoned| poisoned.into_inner());
            let dead = tracker.record(outcome, now);
            (dead, tracker.consecutive_failures())
        };

        if outcome == RequestOutcome::Unauthorized && !dead {
            warn!("API key rejected (401), {} consecutive", count);
        }

        if dead && !self.rejected.swap(true, Ordering::SeqCst) {
            error!(
                "API key rejected {} times in a row for over {}s - discarding key and re-enrolling",
                count,
                DEAD_KEY_MIN_DURATION.as_secs()
            );
            self.session.cancel();
        }
    }

    /// True once the key has been declared dead.
    pub fn key_rejected(&self) -> bool {
        self.rejected.load(Ordering::SeqCst)
    }
}

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
    /// Every adapter's MAC, so the server can send Wake-on-LAN packets
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub mac_addresses: Vec<String>,
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
    /// Raw Netdata /api/v3/data response for machine-wide network throughput
    /// (kept for servers that predate per-adapter rows)
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_net: Option<serde_json::Value>,
    /// Processor queue length (Windows' stand-in for load average)
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_cpu_queue: Option<serde_json::Value>,
    /// Per-app CPU utilisation, grouped by instance and dimension
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_apps_cpu: Option<serde_json::Value>,
    /// Per-app memory usage, grouped by instance and dimension
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_apps_mem: Option<serde_json::Value>,
    /// Per-physical-disk busy time, grouped by instance and dimension
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_disk_util: Option<serde_json::Value>,
    /// Swap / page file usage
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_swap: Option<serde_json::Value>,
    /// Per-adapter throughput, grouped by instance and dimension
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_net_interfaces: Option<serde_json::Value>,
    /// Per-adapter errors, grouped by instance and dimension
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_net_errors: Option<serde_json::Value>,
    /// Per-adapter drops, grouped by instance and dimension
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_net_drops: Option<serde_json::Value>,
    /// Per-adapter link speed, grouped by instance and dimension
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_net_speed: Option<serde_json::Value>,
}

// ============================================================================
// Metrics Collector
// ============================================================================

/// Simple metrics collector - fetches from Netdata and forwards to Laravel
pub struct MetricsCollector {
    config: Config,
    client: reqwest::Client,
    hostname: String,
    mac_addresses: Vec<String>,
}

impl MetricsCollector {
    /// Create a new metrics collector
    pub fn new(config: Config, hostname: String, mac_addresses: Vec<String>) -> Result<Self> {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(10))
            .build()
            .context("Failed to create HTTP client")?;

        Ok(Self {
            config,
            client,
            hostname,
            mac_addresses,
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
        self.fetch_netdata_query(context, None).await
    }

    /// `group_by` keeps instances apart: without it Netdata averages every
    /// instance of a context together (e.g. all disk volumes into one number).
    async fn fetch_netdata_query(
        &self,
        context: &str,
        group_by: Option<&str>,
    ) -> Option<serde_json::Value> {
        let group_by = group_by
            .map(|group| format!("&group_by={}", group))
            .unwrap_or_default();
        let url = format!(
            "{}/api/v3/data?contexts={}&format=json&points=1&time_group=average{}",
            self.config.netdata_url, context, group_by
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

        let by_instance = Some(NETDATA_GROUP_BY_INSTANCE);

        // Fetch all contexts in parallel
        let (
            netdata_info,
            netdata_cpu,
            netdata_ram,
            netdata_load,
            netdata_uptime,
            netdata_disk,
            netdata_net,
            netdata_cpu_queue,
            netdata_apps_cpu,
            netdata_apps_mem,
            netdata_disk_util,
            netdata_swap,
            netdata_net_interfaces,
            netdata_net_errors,
            netdata_net_drops,
            netdata_net_speed,
        ) = tokio::join!(
            self.fetch_netdata_info(),
            self.fetch_netdata_context("system.cpu"),
            self.fetch_netdata_context("system.ram"),
            self.fetch_netdata_context("system.load"),
            self.fetch_netdata_context("system.uptime"),
            self.fetch_netdata_query("disk.space", by_instance),
            self.fetch_netdata_context("system.net"),
            self.fetch_netdata_context("system.processor_queue_length"),
            self.fetch_netdata_query("app.cpu_utilization", by_instance),
            self.fetch_netdata_query("app.mem_usage", by_instance),
            self.fetch_netdata_query("disk.util", by_instance),
            self.fetch_netdata_context("mem.swap"),
            self.fetch_netdata_query("net.net", by_instance),
            self.fetch_netdata_query("net.errors", by_instance),
            self.fetch_netdata_query("net.drops", by_instance),
            self.fetch_netdata_query("net.speed", by_instance),
        );

        RawMetricsPayload {
            hostname: self.hostname.clone(),
            timestamp: Utc::now().to_rfc3339(),
            agent_version: env!("CARGO_PKG_VERSION").to_string(),
            mac_addresses: self.mac_addresses.clone(),
            netdata_info,
            netdata_cpu,
            netdata_ram,
            netdata_load,
            netdata_uptime,
            netdata_disk,
            netdata_net,
            netdata_cpu_queue,
            netdata_apps_cpu,
            netdata_apps_mem,
            netdata_disk_util,
            netdata_swap,
            netdata_net_interfaces,
            netdata_net_errors,
            netdata_net_drops,
            netdata_net_speed,
        }
    }

    /// Submit raw metrics to Laravel backend
    pub async fn submit_metrics(
        &self,
        metrics: &RawMetricsPayload,
        api_key: &str,
    ) -> RequestOutcome {
        let url = format!("{}/api/metrics", self.config.base_url);

        debug!("Submitting metrics to backend: {}", url);

        let response = match self
            .client
            .post(&url)
            .header("X-Agent-Key", api_key)
            .json(metrics)
            .send()
            .await
        {
            Ok(resp) => resp,
            Err(e) => {
                warn!("Failed to submit metrics (network error): {}", e);
                return RequestOutcome::Inconclusive;
            }
        };

        let status = response.status();
        let outcome = RequestOutcome::from_status(status);

        if outcome == RequestOutcome::Success {
            debug!("Metrics submitted successfully");
        } else {
            let body = response.text().await.unwrap_or_default();
            warn!("Metrics submission failed: {} - {}", status, body);
        }

        outcome
    }

    /// Collect and submit metrics in one operation
    pub async fn collect_and_submit(&self, api_key: &str) -> RequestOutcome {
        let metrics = self.collect_metrics().await;
        let outcome = self.submit_metrics(&metrics, api_key).await;

        if outcome == RequestOutcome::Success {
            if metrics.netdata_cpu.is_some() || metrics.netdata_ram.is_some() {
                info!("Metrics submitted (raw Netdata data)");
            } else {
                warn!("Metrics submitted with no Netdata data (Netdata may be unavailable)");
            }
        }

        outcome
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

    /// Start metrics collection loop (runs until the session token is
    /// cancelled; pauses while the machine sleeps)
    pub async fn start_metrics_loop(
        &self,
        api_key: String,
        cancellation_token: CancellationToken,
        key_health: Arc<KeyHealth>,
        mut power_gate: PowerGate,
    ) {
        info!(
            "Starting metrics collection loop (interval: {}s)",
            self.config.metrics_interval
        );

        if !self.check_netdata_available().await {
            warn!("Netdata is not available at startup - metrics will be limited");
        }

        let interval = Duration::from_secs(self.config.metrics_interval);
        while power_gate.tick(interval, &cancellation_token).await {
            let outcome = self.collect_and_submit(&api_key).await;
            key_health.record(outcome);
        }

        info!("Metrics collection loop stopped");
    }

    /// Send a lightweight heartbeat to the backend
    pub async fn send_heartbeat(&self, api_key: &str) -> RequestOutcome {
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
                let outcome = RequestOutcome::from_status(status);

                match outcome {
                    RequestOutcome::Success => debug!("Heartbeat OK"),
                    RequestOutcome::Unauthorized => {
                        let body = resp.text().await.unwrap_or_default();
                        warn!("Heartbeat auth failed (401): {}", body);
                    }
                    RequestOutcome::Inconclusive
                        if status == reqwest::StatusCode::TOO_MANY_REQUESTS =>
                    {
                        warn!("Heartbeat rate limited (429)");
                    }
                    RequestOutcome::Inconclusive => {
                        let body = resp.text().await.unwrap_or_default();
                        warn!("Heartbeat failed ({}): {}", status.as_u16(), body);
                    }
                }

                outcome
            }
            Err(e) => {
                warn!("Heartbeat network error: {}", e);
                RequestOutcome::Inconclusive
            }
        }
    }

    /// Start heartbeat loop (runs until the session token is cancelled;
    /// pauses while the machine sleeps, beats at once on wake)
    pub async fn start_heartbeat_loop(
        &self,
        api_key: String,
        cancellation_token: CancellationToken,
        key_health: Arc<KeyHealth>,
        mut power_gate: PowerGate,
    ) {
        info!(
            "Starting heartbeat loop (interval: {}s)",
            self.config.heartbeat_interval
        );

        let interval = Duration::from_secs(self.config.heartbeat_interval);
        while power_gate.tick(interval, &cancellation_token).await {
            let outcome = self.send_heartbeat(&api_key).await;
            key_health.record(outcome);
        }

        info!("Heartbeat loop stopped");
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn empty_payload() -> RawMetricsPayload {
        RawMetricsPayload {
            hostname: "test-host".to_string(),
            timestamp: "2026-10-01T10:00:00Z".to_string(),
            agent_version: "0.6.0".to_string(),
            mac_addresses: vec![],
            netdata_info: None,
            netdata_cpu: None,
            netdata_ram: None,
            netdata_load: None,
            netdata_uptime: None,
            netdata_disk: None,
            netdata_net: None,
            netdata_cpu_queue: None,
            netdata_apps_cpu: None,
            netdata_apps_mem: None,
            netdata_disk_util: None,
            netdata_swap: None,
            netdata_net_interfaces: None,
            netdata_net_errors: None,
            netdata_net_drops: None,
            netdata_net_speed: None,
        }
    }

    fn netdata_response<S: Serialize>(ids: &[S], averages: &[f64]) -> serde_json::Value {
        serde_json::json!({
            "view": { "dimensions": { "ids": ids, "sts": { "avg": averages } } }
        })
    }

    #[test]
    fn serialises_windows_performance_fields() {
        let adapter = "Intel[R] Ethernet Connection [17] I219-LM@node";
        let payload = RawMetricsPayload {
            netdata_cpu_queue: Some(netdata_response(&["threads"], &[0.265])),
            netdata_apps_cpu: Some(netdata_response(
                &["user,app.Netdata_Agent_cpu_utilization@node"],
                &[1.2472645],
            )),
            netdata_apps_mem: Some(netdata_response(
                &["rss,app.Dell_TechHub_mem_usage@node"],
                &[484.723591],
            )),
            netdata_disk_util: Some(netdata_response(&["utilization,disk_util.Disk 0@node"], &[0.81])),
            netdata_swap: Some(netdata_response(&["free", "used"], &[11621.7496083, 5474.9808583])),
            netdata_net_interfaces: Some(netdata_response(
                &[&format!("received,net.{adapter}"), &format!("sent,net.{adapter}")],
                &[14.0066736, -6.87891],
            )),
            netdata_net_errors: Some(netdata_response(&[&format!("inbound,net_errors.{adapter}")], &[0.0])),
            netdata_net_drops: Some(netdata_response(&[&format!("inbound,net_drops.{adapter}")], &[0.0])),
            netdata_net_speed: Some(netdata_response(&[&format!("speed,net_speed.{adapter}")], &[1000000.0])),
            ..empty_payload()
        };

        let json: serde_json::Value = serde_json::to_value(&payload).unwrap();

        assert_eq!(json["netdata_cpu_queue"]["view"]["dimensions"]["sts"]["avg"][0], 0.265);
        assert_eq!(
            json["netdata_apps_cpu"]["view"]["dimensions"]["ids"][0],
            "user,app.Netdata_Agent_cpu_utilization@node"
        );
        assert_eq!(json["netdata_apps_mem"]["view"]["dimensions"]["sts"]["avg"][0], 484.723591);
        assert_eq!(json["netdata_disk_util"]["view"]["dimensions"]["sts"]["avg"][0], 0.81);
        assert_eq!(json["netdata_swap"]["view"]["dimensions"]["ids"][1], "used");
        assert_eq!(json["netdata_net_interfaces"]["view"]["dimensions"]["sts"]["avg"][1], -6.87891);
        assert!(json.get("netdata_net_errors").is_some());
        assert!(json.get("netdata_net_drops").is_some());
        assert_eq!(json["netdata_net_speed"]["view"]["dimensions"]["sts"]["avg"][0], 1000000.0);
    }

    #[test]
    fn omits_missing_netdata_fields() {
        let json: serde_json::Value = serde_json::to_value(empty_payload()).unwrap();
        let keys: Vec<&str> = json.as_object().unwrap().keys().map(String::as_str).collect();

        assert_eq!(keys.len(), 3);
        assert!(keys.contains(&"hostname"));
        assert!(keys.contains(&"timestamp"));
        assert!(keys.contains(&"agent_version"));
    }

    #[test]
    fn serialises_mac_addresses() {
        let payload = RawMetricsPayload {
            mac_addresses: vec!["bc:24:11:8d:62:14".to_string()],
            ..empty_payload()
        };

        let json: serde_json::Value = serde_json::to_value(&payload).unwrap();

        assert_eq!(json["mac_addresses"][0], "bc:24:11:8d:62:14");
    }

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
            ..empty_payload()
        };

        let json = serde_json::to_string(&payload).unwrap();
        assert!(json.contains("test-host"));
        assert!(json.contains("netdata_cpu"));
        assert!(json.contains("10.5"));
    }

    use reqwest::StatusCode;

    fn tracker() -> AuthFailureTracker {
        AuthFailureTracker::new(DEAD_KEY_MIN_FAILURES, DEAD_KEY_MIN_DURATION)
    }

    #[test]
    fn classifies_status_codes() {
        assert_eq!(RequestOutcome::from_status(StatusCode::OK), RequestOutcome::Success);
        assert_eq!(RequestOutcome::from_status(StatusCode::NO_CONTENT), RequestOutcome::Success);
        assert_eq!(
            RequestOutcome::from_status(StatusCode::UNAUTHORIZED),
            RequestOutcome::Unauthorized
        );
        for status in [
            StatusCode::TOO_MANY_REQUESTS,
            StatusCode::INTERNAL_SERVER_ERROR,
            StatusCode::BAD_GATEWAY,
            StatusCode::SERVICE_UNAVAILABLE,
            StatusCode::GATEWAY_TIMEOUT,
            StatusCode::FORBIDDEN,
            StatusCode::NOT_FOUND,
            StatusCode::UNPROCESSABLE_ENTITY,
        ] {
            assert_eq!(
                RequestOutcome::from_status(status),
                RequestOutcome::Inconclusive,
                "{status}"
            );
        }
    }

    #[test]
    fn trips_after_sustained_401s() {
        let mut t = tracker();
        let start = Instant::now();
        let mut tripped_at = None;
        // A 401 every 30s (heartbeat cadence).
        for i in 0..20u64 {
            if t.record(RequestOutcome::Unauthorized, start + Duration::from_secs(i * 30)) {
                tripped_at = Some(i);
                break;
            }
        }
        // 7th failure (index 6) is the first one at >= 180s after the first.
        assert_eq!(tripped_at, Some(6));
    }

    #[test]
    fn short_burst_of_401s_does_not_trip() {
        let mut t = tracker();
        let start = Instant::now();
        // Many 401s inside two minutes: count reached, duration not.
        for i in 0..50u64 {
            assert!(!t.record(RequestOutcome::Unauthorized, start + Duration::from_secs(i * 2)));
        }
    }

    #[test]
    fn few_401s_over_long_time_do_not_trip() {
        let mut t = tracker();
        let start = Instant::now();
        // Duration reached, count not.
        for i in 0..(DEAD_KEY_MIN_FAILURES as u64 - 1) {
            assert!(!t.record(RequestOutcome::Unauthorized, start + Duration::from_secs(i * 600)));
        }
    }

    #[test]
    fn success_resets_the_streak() {
        let mut t = tracker();
        let start = Instant::now();
        for i in 0..5u64 {
            assert!(!t.record(RequestOutcome::Unauthorized, start + Duration::from_secs(i * 30)));
        }
        assert!(!t.record(RequestOutcome::Success, start + Duration::from_secs(160)));
        assert_eq!(t.consecutive_failures(), 0);
        // The streak starts over: 6 more failures at 30s spacing span only 150s.
        for i in 0..6u64 {
            assert!(!t.record(
                RequestOutcome::Unauthorized,
                start + Duration::from_secs(170 + i * 30)
            ));
        }
        assert!(t.record(RequestOutcome::Unauthorized, start + Duration::from_secs(170 + 6 * 30)));
    }

    #[test]
    fn inconclusive_outcomes_never_trip_or_reset() {
        let mut t = tracker();
        let start = Instant::now();
        for i in 0..100u64 {
            assert!(!t.record(RequestOutcome::Inconclusive, start + Duration::from_secs(i * 30)));
        }
        assert_eq!(t.consecutive_failures(), 0);

        // 429/5xx/network errors interleaved with 401s neither count nor reset.
        let mut t = tracker();
        let mut tripped = false;
        for i in 0..14u64 {
            let outcome = if i % 2 == 0 {
                RequestOutcome::Unauthorized
            } else {
                RequestOutcome::Inconclusive
            };
            tripped = t.record(outcome, start + Duration::from_secs(i * 30));
            if tripped {
                break;
            }
        }
        assert!(tripped);
        assert_eq!(t.consecutive_failures(), DEAD_KEY_MIN_FAILURES);
    }

    #[test]
    fn key_health_cancels_session_once() {
        let token = CancellationToken::new();
        let health = KeyHealth::new(token.clone());
        health.record(RequestOutcome::Unauthorized);
        health.record(RequestOutcome::Success);
        assert!(!health.key_rejected());
        assert!(!token.is_cancelled());

        let start = Instant::now();
        for i in 0..DEAD_KEY_MIN_FAILURES as u64 {
            health.record_at(RequestOutcome::Unauthorized, start + Duration::from_secs(i * 30));
            assert!(!token.is_cancelled());
        }
        health.record_at(RequestOutcome::Unauthorized, start + DEAD_KEY_MIN_DURATION);
        assert!(health.key_rejected());
        assert!(token.is_cancelled());

        // Further failures do not re-trigger anything.
        health.record_at(RequestOutcome::Unauthorized, start + DEAD_KEY_MIN_DURATION * 2);
        assert!(health.key_rejected());
    }
}
