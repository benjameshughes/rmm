//! Simplified metrics collector - forwards raw Netdata JSON to Laravel
//!
//! The agent's job is simple:
//! 1. Fetch raw JSON from Netdata v3 API
//! 2. Forward it to Laravel
//! 3. Let Laravel handle all parsing

use anyhow::{Context, Result};
use chrono::Utc;
use serde::{Deserialize, Serialize};
use std::future::Future;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};
use tokio_util::sync::CancellationToken;
use tracing::{debug, error, info, warn};

use crate::config::{Config, NETDATA_GROUP_BY_INSTANCE, NETDATA_WINDOW_MAX_SECS};
use crate::power::PowerGate;
use crate::startup_grace::{Milestone, StartupProgress};

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
// Heartbeat interval (set by the server)
// ============================================================================

/// The part of a heartbeat response the agent uses. Older servers send no
/// interval (or no JSON at all).
#[derive(Debug, Default, Deserialize)]
struct HeartbeatResponse {
    #[serde(default)]
    heartbeat_interval_seconds: Option<u64>,
}

/// The interval a heartbeat response asks for, if it asks for one.
fn requested_heartbeat_interval(body: &str) -> Option<u64> {
    serde_json::from_str::<HeartbeatResponse>(body)
        .ok()
        .and_then(|response| response.heartbeat_interval_seconds)
}

/// Keep a server-requested interval within `[min, max]` seconds.
fn clamp_heartbeat_interval(requested: u64, min: u64, max: u64) -> Duration {
    Duration::from_secs(requested.clamp(min, max.max(min)))
}

/// Beat at once, then every `interval`, adopting whatever interval each
/// beat's response asks for (clamped) from the next tick on. Pauses while the
/// machine sleeps. `beat` returns the requested interval in seconds.
async fn run_heartbeats<F, Fut>(
    mut interval: Duration,
    bounds: (u64, u64),
    power_gate: &mut PowerGate,
    cancellation_token: &CancellationToken,
    mut beat: F,
) where
    F: FnMut() -> Fut,
    Fut: Future<Output = Option<u64>>,
{
    let mut ready = power_gate.wait_until_allowed(cancellation_token).await;

    while ready {
        if let Some(requested) = beat().await {
            let next = clamp_heartbeat_interval(requested, bounds.0, bounds.1);
            if next != interval {
                info!(
                    "Heartbeat interval changed by server: {}s -> {}s",
                    interval.as_secs(),
                    next.as_secs()
                );
                interval = next;
            }
        }

        ready = power_gate.tick(interval, cancellation_token).await;
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
    /// Set by builds that never run commands, so the server never queues
    /// any for this device. Omitted (not false) by Windows builds.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub monitor_only: Option<bool>,
    /// Raw Netdata /api/v3/info response (optional)
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_info: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for CPU metrics
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_cpu: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for RAM metrics
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_ram: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for load metrics (Linux only;
    /// Windows has no load average)
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_load: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for uptime metrics
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_uptime: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for disk space metrics
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_disk: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for inode usage per filesystem
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_disk_inodes: Option<serde_json::Value>,
    /// Raw Netdata /api/v3/data response for machine-wide network throughput
    /// (kept for servers that predate per-adapter rows)
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_net: Option<serde_json::Value>,
    /// Processor queue length (Windows' stand-in for load average)
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_cpu_queue: Option<serde_json::Value>,
    /// Running and blocked process counts
    #[serde(skip_serializing_if = "Option::is_none")]
    pub netdata_processes: Option<serde_json::Value>,
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
    /// Failed units, pending reboot and pending updates (Linux only)
    #[serde(skip_serializing_if = "Option::is_none")]
    pub linux_health: Option<crate::linux_health::LinuxHealth>,
    /// Backup status files (Linux only; omitted without a status directory)
    #[serde(skip_serializing_if = "Option::is_none")]
    pub backups: Option<Vec<crate::backups::BackupStatus>>,
}

// ============================================================================
// Netdata queries
// ============================================================================

/// How a context's history is folded before it is sent.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum NetdataShape {
    /// One raw point per second across the window, newest first, so the
    /// server can see spikes and gaps that an average would hide.
    PerSecond,
    /// One point averaged across the window. Per-app contexts have hundreds
    /// of dimensions; a per-second series of each would be mostly noise.
    Average,
}

/// Seconds of Netdata history fetched each round: one metrics interval, so
/// consecutive submissions cover the timeline end to end without gaps or
/// overlap. Capped at `NETDATA_WINDOW_MAX_SECS`, and at least one second so
/// a zero interval still asks for a point.
pub fn netdata_window_secs(metrics_interval: u64) -> u64 {
    metrics_interval.clamp(1, NETDATA_WINDOW_MAX_SECS)
}

/// The /api/v3/data URL for one context. `tier=0` pins the per-second tier
/// (higher tiers are per-minute and would interpolate), and `unaligned`
/// stops Netdata snapping the window to a multiple of the point size.
/// `group_by` keeps instances apart: without it Netdata averages every
/// instance of a context together (e.g. all disk volumes into one number).
fn netdata_data_url(
    netdata_url: &str,
    context: &str,
    group_by: Option<&str>,
    window_secs: u64,
    shape: NetdataShape,
) -> String {
    let window = match shape {
        NetdataShape::PerSecond => {
            format!("after=-{window_secs}&points={window_secs}&tier=0&options=unaligned")
        }
        NetdataShape::Average => format!("after=-{window_secs}&points=1&time_group=average"),
    };
    let group_by = group_by
        .map(|group| format!("&group_by={}", group))
        .unwrap_or_default();

    format!("{netdata_url}/api/v3/data?contexts={context}&format=json&{window}{group_by}")
}

// ============================================================================
// Metrics Collector
// ============================================================================

/// Simple metrics collector - fetches from Netdata and forwards to Laravel
pub struct MetricsCollector {
    config: Config,
    /// Laravel API requests (heartbeat, metrics submission).
    client: reqwest::Client,
    /// Local Netdata requests, never on a reused connection.
    netdata_client: reqwest::Client,
    hostname: String,
    mac_addresses: Vec<String>,
    /// Linux health checks (keeps the apt result between submissions).
    #[cfg(not(windows))]
    health: tokio::sync::Mutex<crate::linux_health::HealthCollector>,
}

impl MetricsCollector {
    /// Create a new metrics collector
    pub fn new(config: Config, hostname: String, mac_addresses: Vec<String>) -> Result<Self> {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(10))
            .build()
            .context("Failed to create HTTP client")?;
        // Reused keep-alive connections to Netdata on Windows randomly hang
        // until the timeout; a fresh connection per request never has.
        let netdata_client = reqwest::Client::builder()
            .timeout(Duration::from_secs(10))
            .pool_max_idle_per_host(0)
            .build()
            .context("Failed to create Netdata HTTP client")?;

        Ok(Self {
            config,
            client,
            netdata_client,
            hostname,
            mac_addresses,
            #[cfg(not(windows))]
            health: tokio::sync::Mutex::new(Default::default()),
        })
    }

    /// Fetch raw JSON from Netdata v3 API (no parsing)
    async fn fetch_netdata_info(&self) -> Option<serde_json::Value> {
        let url = format!("{}/api/v3/info", self.config.netdata_url);
        debug!("Fetching Netdata info from: {}", url);

        match self.netdata_client.get(&url).send().await {
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

    /// Per-second history of a single-instance context
    async fn fetch_netdata_context(&self, context: &str) -> Option<serde_json::Value> {
        self.fetch_netdata_query(context, None, NetdataShape::PerSecond)
            .await
    }

    /// Per-second history of every instance of a context
    async fn fetch_netdata_instances(&self, context: &str) -> Option<serde_json::Value> {
        self.fetch_netdata_query(
            context,
            Some(NETDATA_GROUP_BY_INSTANCE),
            NetdataShape::PerSecond,
        )
        .await
    }

    /// Fetch raw data from a Netdata v3 API context (no parsing). Failures
    /// only log at debug: each platform lacks some contexts (Windows has no
    /// `system.load`, Linux no `system.processor_queue_length`), and the
    /// whole query list is asked for on every round.
    async fn fetch_netdata_query(
        &self,
        context: &str,
        group_by: Option<&str>,
        shape: NetdataShape,
    ) -> Option<serde_json::Value> {
        let url = netdata_data_url(
            &self.config.netdata_url,
            context,
            group_by,
            netdata_window_secs(self.config.metrics_interval),
            shape,
        );
        debug!("Fetching Netdata {} from: {}", context, url);

        match self.netdata_client.get(&url).send().await {
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
            netdata_disk_inodes,
            netdata_net,
            netdata_cpu_queue,
            netdata_processes,
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
            self.fetch_netdata_instances("disk.space"),
            self.fetch_netdata_instances("disk.inodes"),
            self.fetch_netdata_context("system.net"),
            self.fetch_netdata_context("system.processor_queue_length"),
            self.fetch_netdata_context("system.processes"),
            self.fetch_netdata_query("app.cpu_utilization", by_instance, NetdataShape::Average),
            self.fetch_netdata_query("app.mem_usage", by_instance, NetdataShape::Average),
            self.fetch_netdata_instances("disk.util"),
            self.fetch_netdata_context("mem.swap"),
            self.fetch_netdata_instances("net.net"),
            self.fetch_netdata_instances("net.errors"),
            self.fetch_netdata_instances("net.drops"),
            self.fetch_netdata_instances("net.speed"),
        );

        RawMetricsPayload {
            hostname: self.hostname.clone(),
            timestamp: Utc::now().to_rfc3339(),
            agent_version: env!("CARGO_PKG_VERSION").to_string(),
            mac_addresses: self.mac_addresses.clone(),
            monitor_only: cfg!(not(windows)).then_some(true),
            netdata_info,
            netdata_cpu,
            netdata_ram,
            netdata_load,
            netdata_uptime,
            netdata_disk,
            netdata_disk_inodes,
            netdata_net,
            netdata_cpu_queue,
            netdata_processes,
            netdata_apps_cpu,
            netdata_apps_mem,
            netdata_disk_util,
            netdata_swap,
            netdata_net_interfaces,
            netdata_net_errors,
            netdata_net_drops,
            netdata_net_speed,
            linux_health: None,
            backups: None,
        }
    }

    /// Submit raw metrics to Laravel backend
    pub async fn submit_metrics<P: Serialize>(&self, metrics: &P, api_key: &str) -> RequestOutcome {
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

    /// Linux health for this round (Linux only). Runs even when Netdata is
    /// down, so a box without Netdata still reports failed units and updates.
    #[cfg(not(windows))]
    async fn collect_linux_health(&self) -> Option<crate::linux_health::LinuxHealth> {
        if !cfg!(target_os = "linux") {
            return None;
        }

        Some(
            self.health
                .lock()
                .await
                .collect(
                    Duration::from_secs(self.config.health_command_timeout),
                    Duration::from_secs(self.config.apt_check_interval),
                )
                .await,
        )
    }

    #[cfg(windows)]
    async fn collect_linux_health(&self) -> Option<crate::linux_health::LinuxHealth> {
        None
    }

    /// Collect and submit metrics in one operation
    pub async fn collect_and_submit(&self, api_key: &str) -> RequestOutcome {
        let mut metrics = self.collect_metrics().await;
        metrics.linux_health = self.collect_linux_health().await;
        metrics.backups = crate::backups::collect().await;
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

        match self.netdata_client.get(&url).send().await {
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
        startup: StartupProgress,
    ) {
        info!(
            "Starting metrics collection loop (interval: {}s)",
            self.config.metrics_interval
        );

        if !self.check_netdata_available().await {
            warn!("Netdata is not available at startup - metrics will be limited");
        }

        // Submit straight away at start so the server sees this version
        // quickly, then on the interval.
        let interval = Duration::from_secs(self.config.metrics_interval);
        let mut ready = power_gate.wait_until_allowed(&cancellation_token).await;
        while ready {
            let outcome = self.collect_and_submit(&api_key).await;
            key_health.record(outcome);
            if outcome == RequestOutcome::Success {
                startup.mark(Milestone::MetricsPosted);
            }
            ready = power_gate.tick(interval, &cancellation_token).await;
        }

        info!("Metrics collection loop stopped");
    }

    /// Send a lightweight heartbeat to the backend. Also returns the interval
    /// the server asked for, if any.
    pub async fn send_heartbeat(&self, api_key: &str) -> (RequestOutcome, Option<u64>) {
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
                let mut requested_interval = None;

                match outcome {
                    RequestOutcome::Success => {
                        debug!("Heartbeat OK");
                        let body = resp.text().await.unwrap_or_default();
                        requested_interval = requested_heartbeat_interval(&body);
                    }
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

                (outcome, requested_interval)
            }
            Err(e) => {
                warn!("Heartbeat network error: {}", e);
                (RequestOutcome::Inconclusive, None)
            }
        }
    }

    /// Start heartbeat loop (runs until the session token is cancelled;
    /// beats at once at start and on wake, pauses while the machine sleeps,
    /// follows the interval the server asks for)
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

        let api_key = &api_key;
        let key_health = &key_health;
        run_heartbeats(
            Duration::from_secs(self.config.heartbeat_interval),
            (
                self.config.heartbeat_interval_min,
                self.config.heartbeat_interval_max,
            ),
            &mut power_gate,
            &cancellation_token,
            || async move {
                let (outcome, requested_interval) = self.send_heartbeat(api_key).await;
                key_health.record(outcome);
                requested_interval
            },
        )
        .await;

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
            monitor_only: None,
            netdata_info: None,
            netdata_cpu: None,
            netdata_ram: None,
            netdata_load: None,
            netdata_uptime: None,
            netdata_disk: None,
            netdata_disk_inodes: None,
            netdata_net: None,
            netdata_cpu_queue: None,
            netdata_processes: None,
            netdata_apps_cpu: None,
            netdata_apps_mem: None,
            netdata_disk_util: None,
            netdata_swap: None,
            netdata_net_interfaces: None,
            netdata_net_errors: None,
            netdata_net_drops: None,
            netdata_net_speed: None,
            linux_health: None,
            backups: None,
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
    fn sends_backups_only_when_there_is_a_status_directory() {
        let without = serde_json::to_value(empty_payload()).unwrap();
        assert!(without.get("backups").is_none());

        let payload = RawMetricsPayload {
            backups: Some(vec![]),
            ..empty_payload()
        };
        let with = serde_json::to_value(&payload).unwrap();
        assert_eq!(with["backups"], serde_json::json!([]));
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

    #[test]
    fn the_window_follows_the_metrics_interval() {
        assert_eq!(netdata_window_secs(60), 60);
        assert_eq!(netdata_window_secs(15), 15);
        assert_eq!(netdata_window_secs(300), 300);
        assert_eq!(netdata_window_secs(3600), 300);
        assert_eq!(netdata_window_secs(0), 1);
    }

    #[test]
    fn asks_for_one_point_per_second_across_the_interval() {
        let url = netdata_data_url(
            "http://127.0.0.1:19999",
            "system.cpu",
            None,
            netdata_window_secs(60),
            NetdataShape::PerSecond,
        );

        assert_eq!(
            url,
            "http://127.0.0.1:19999/api/v3/data?contexts=system.cpu&format=json\
             &after=-60&points=60&tier=0&options=unaligned"
        );
        assert!(!url.contains("time_group"));
    }

    #[test]
    fn keeps_instances_apart_in_per_second_queries() {
        let url = netdata_data_url(
            "http://127.0.0.1:19999",
            "disk.inodes",
            Some(NETDATA_GROUP_BY_INSTANCE),
            netdata_window_secs(30),
            NetdataShape::PerSecond,
        );

        assert_eq!(
            url,
            "http://127.0.0.1:19999/api/v3/data?contexts=disk.inodes&format=json\
             &after=-30&points=30&tier=0&options=unaligned&group_by=instance,dimension"
        );
    }

    #[test]
    fn averages_per_app_contexts_over_the_window() {
        let url = netdata_data_url(
            "http://127.0.0.1:19999",
            "app.cpu_utilization",
            Some(NETDATA_GROUP_BY_INSTANCE),
            netdata_window_secs(3600),
            NetdataShape::Average,
        );

        assert_eq!(
            url,
            "http://127.0.0.1:19999/api/v3/data?contexts=app.cpu_utilization&format=json\
             &after=-300&points=1&time_group=average&group_by=instance,dimension"
        );
    }

    #[test]
    fn serialises_the_linux_payload() {
        let rows = |values: &[f64]| {
            serde_json::json!({
                "result": {
                    "labels": ["time", "load1", "load5", "load15"],
                    "data": [[1791103211, values[0], values[1], values[2]], [1791103210, null, null, null]]
                }
            })
        };
        let payload = RawMetricsPayload {
            monitor_only: Some(true),
            netdata_load: Some(rows(&[0.12, 0.08, 0.05])),
            netdata_processes: Some(netdata_response(&["running", "blocked"], &[2.0, 0.0])),
            netdata_disk_inodes: Some(netdata_response(&["used,disk_inodes./@node"], &[12.5])),
            linux_health: Some(crate::linux_health::LinuxHealth {
                failed_units: Some(vec!["nginx.service".to_string()]),
                reboot_required: true,
                pending_updates: Some(4),
                pending_security_updates: Some(1),
                checked_updates_at: Some("2026-10-04T09:00:00Z".to_string()),
            }),
            ..empty_payload()
        };

        let json: serde_json::Value = serde_json::to_value(&payload).unwrap();

        assert_eq!(json["monitor_only"], true);
        assert_eq!(json["netdata_load"]["result"]["data"][0][1], 0.12);
        assert!(json["netdata_load"]["result"]["data"][1][1].is_null());
        assert_eq!(json["netdata_processes"]["view"]["dimensions"]["ids"][1], "blocked");
        assert_eq!(json["netdata_disk_inodes"]["view"]["dimensions"]["sts"]["avg"][0], 12.5);
        assert_eq!(json["linux_health"]["failed_units"][0], "nginx.service");
        assert_eq!(json["linux_health"]["reboot_required"], true);
        assert_eq!(json["linux_health"]["pending_updates"], 4);
        assert_eq!(json["linux_health"]["pending_security_updates"], 1);
    }

    #[tokio::test]
    async fn netdata_requests_never_reuse_a_connection() {
        use std::sync::atomic::AtomicUsize;
        use tokio::io::{AsyncReadExt, AsyncWriteExt};

        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let accepted = Arc::new(AtomicUsize::new(0));
        let counter = accepted.clone();
        tokio::spawn(async move {
            loop {
                let (mut socket, _) = listener.accept().await.unwrap();
                counter.fetch_add(1, Ordering::SeqCst);
                tokio::spawn(async move {
                    let mut request = [0u8; 4096];
                    while matches!(socket.read(&mut request).await, Ok(read) if read > 0) {
                        let reply = b"HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n{}";
                        let _ = socket.write_all(reply).await;
                    }
                });
            }
        });
        let config = Config {
            netdata_url: format!("http://{address}"),
            ..Config::default()
        };
        let collector = MetricsCollector::new(config, "pc-01".to_string(), Vec::new()).unwrap();

        assert!(collector.check_netdata_available().await);
        assert!(collector.check_netdata_available().await);
        assert_eq!(accepted.load(Ordering::SeqCst), 2);
    }

    #[tokio::test]
    async fn without_netdata_the_payload_still_identifies_the_device() {
        let config = Config {
            netdata_url: "http://127.0.0.1:1".to_string(),
            ..Config::default()
        };
        let collector = MetricsCollector::new(
            config,
            "web-01".to_string(),
            vec!["bc:24:11:8d:62:14".to_string()],
        )
        .unwrap();

        assert!(!collector.check_netdata_available().await);

        let json: serde_json::Value =
            serde_json::to_value(collector.collect_metrics().await).unwrap();
        let keys: Vec<&str> = json.as_object().unwrap().keys().map(String::as_str).collect();

        assert!(keys.iter().all(|key| !key.starts_with("netdata_")), "{keys:?}");
        assert_eq!(json["hostname"], "web-01");
        assert_eq!(json["mac_addresses"][0], "bc:24:11:8d:62:14");
        if cfg!(windows) {
            assert!(json.get("monitor_only").is_none());
        } else {
            assert_eq!(json["monitor_only"], true);
        }
    }

    use reqwest::StatusCode;

    #[test]
    fn reads_the_interval_from_a_heartbeat_response() {
        assert_eq!(
            requested_heartbeat_interval(
                r#"{"status":"ok","server_time":"2026-10-03T10:00:00Z","heartbeat_interval_seconds":15}"#
            ),
            Some(15)
        );
    }

    #[test]
    fn older_servers_send_no_interval() {
        for body in [
            r#"{"status":"ok","server_time":"2026-10-03T10:00:00Z"}"#,
            r#"{"status":"ok","heartbeat_interval_seconds":null}"#,
            "",
            "OK",
            r#"{"heartbeat_interval_seconds":-5}"#,
            r#"{"heartbeat_interval_seconds":"15"}"#,
        ] {
            assert_eq!(requested_heartbeat_interval(body), None, "{body}");
        }
    }

    #[test]
    fn clamps_the_requested_interval() {
        let clamp = |secs| clamp_heartbeat_interval(secs, 5, 300).as_secs();

        assert_eq!(clamp(15), 15);
        assert_eq!(clamp(0), 5);
        assert_eq!(clamp(1), 5);
        assert_eq!(clamp(5), 5);
        assert_eq!(clamp(300), 300);
        assert_eq!(clamp(86_400), 300);
    }

    /// Beat times (seconds from start) for a server answering `replies` in turn.
    async fn beat_times(replies: Vec<Option<u64>>, beats: usize) -> Vec<u64> {
        let (power, _notices) = crate::power::PowerController::new();
        let mut gate = power.gate();
        let token = CancellationToken::new();
        let start = tokio::time::Instant::now();
        let times = Arc::new(Mutex::new(Vec::new()));
        let mut replies = replies.into_iter();

        let recorded = times.clone();
        let stop = token.clone();
        run_heartbeats(
            Duration::from_secs(15),
            (5, 300),
            &mut gate,
            &token,
            || {
                let mut times = recorded.lock().unwrap();
                times.push(start.elapsed().as_secs());
                if times.len() == beats {
                    stop.cancel();
                }
                let reply = replies.next().flatten();
                async move { reply }
            },
        )
        .await;

        let times = times.lock().unwrap().clone();
        times
    }

    #[tokio::test(start_paused = true)]
    async fn beats_at_once_then_on_the_default_interval() {
        assert_eq!(beat_times(vec![], 3).await, vec![0, 15, 30]);
    }

    #[tokio::test(start_paused = true)]
    async fn the_next_tick_uses_the_interval_the_server_asked_for() {
        assert_eq!(
            beat_times(vec![Some(15), Some(60), Some(60), None], 5).await,
            vec![0, 15, 75, 135, 195]
        );
    }

    #[tokio::test(start_paused = true)]
    async fn out_of_range_intervals_are_clamped() {
        assert_eq!(
            beat_times(vec![Some(1), Some(1), Some(100_000)], 4).await,
            vec![0, 5, 10, 310]
        );
    }

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
