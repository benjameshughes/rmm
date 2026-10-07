//! Printer snapshots - forwards raw spooler state to Laravel.
//!
//! The agent never decides whether a printer is stuck, offline or in error:
//! it sends the raw PRINTER_STATUS_* / JOB_STATUS_* bits and Laravel reads
//! them. A snapshot goes out after every debounced spooler change (Windows
//! listener thread) and once per metrics interval as a safety net.
#![cfg_attr(not(windows), allow(dead_code))]

use anyhow::{Context, Result};
use chrono::{NaiveDate, SecondsFormat, Utc};
use serde::Serialize;
use std::sync::Arc;
use std::time::{Duration, Instant};
use tokio::sync::mpsc;
use tokio_util::sync::CancellationToken;
use tracing::{debug, error, warn};

use crate::config::{Config, AGENT_VERSION};
use crate::metrics::{KeyHealth, RequestOutcome};
use crate::power::PowerGate;

/// What caused a snapshot.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "lowercase")]
pub enum SnapshotTrigger {
    Change,
    Tick,
}

/// One print job, raw from JOB_INFO_2.
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct PrintJob {
    pub id: u32,
    pub document: String,
    pub user_name: String,
    pub status: u32,
    pub status_text: Option<String>,
    pub submitted: Option<String>,
    pub total_pages: u32,
    pub pages_printed: u32,
    pub size: u32,
    pub position: u32,
    pub priority: u32,
}

/// One printer, raw from PRINTER_INFO_2, with its queue.
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct Printer {
    pub name: String,
    pub port_name: String,
    pub driver_name: String,
    pub status: u32,
    pub attributes: u32,
    pub jobs_count: u32,
    pub jobs: Vec<PrintJob>,
}

/// Everything read from the spooler in one pass.
#[derive(Debug, Clone, PartialEq)]
pub struct SpoolerSnapshot {
    pub spooler_available: bool,
    pub printers: Vec<Printer>,
}

impl SpoolerSnapshot {
    pub fn unavailable() -> Self {
        Self {
            spooler_available: false,
            printers: Vec::new(),
        }
    }
}

/// Body of POST /api/printers.
#[derive(Debug, Serialize)]
pub struct PrinterReport {
    pub hostname: String,
    pub agent_version: String,
    pub trigger: SnapshotTrigger,
    pub collected_at: String,
    pub spooler_available: bool,
    pub printers: Vec<Printer>,
}

impl PrinterReport {
    pub fn new(hostname: &str, trigger: SnapshotTrigger, snapshot: SpoolerSnapshot) -> Self {
        Self {
            hostname: hostname.to_string(),
            agent_version: AGENT_VERSION.to_string(),
            trigger,
            collected_at: Utc::now().to_rfc3339_opts(SecondsFormat::Secs, true),
            spooler_available: snapshot.spooler_available,
            printers: snapshot.printers,
        }
    }
}

/// SYSTEMTIME fields (UTC) as ISO-8601, or None for an unset/invalid time.
pub fn systemtime_to_iso(
    year: u16,
    month: u16,
    day: u16,
    hour: u16,
    minute: u16,
    second: u16,
    millis: u16,
) -> Option<String> {
    NaiveDate::from_ymd_opt(year.into(), month.into(), day.into())?
        .and_hms_milli_opt(hour.into(), minute.into(), second.into(), millis.into())
        .map(|time| time.and_utc().to_rfc3339_opts(SecondsFormat::Secs, true))
}

/// Collapses a burst of spooler signals into one snapshot: due once the
/// spooler has been quiet for `quiet`, or `max_delay` after the first signal
/// if it never goes quiet.
#[derive(Debug)]
pub struct Debouncer {
    quiet: Duration,
    max_delay: Duration,
    first_signal: Option<Instant>,
    last_signal: Option<Instant>,
}

impl Debouncer {
    pub fn new(quiet: Duration, max_delay: Duration) -> Self {
        Self {
            quiet,
            max_delay,
            first_signal: None,
            last_signal: None,
        }
    }

    pub fn signal(&mut self, now: Instant) {
        self.first_signal.get_or_insert(now);
        self.last_signal = Some(now);
    }

    pub fn is_pending(&self) -> bool {
        self.first_signal.is_some()
    }

    /// True once a snapshot is due; resets so the next signal starts afresh.
    pub fn take_due(&mut self, now: Instant) -> bool {
        let (Some(first), Some(last)) = (self.first_signal, self.last_signal) else {
            return false;
        };

        let is_due = now.saturating_duration_since(last) >= self.quiet
            || now.saturating_duration_since(first) >= self.max_delay;
        if is_due {
            self.first_signal = None;
            self.last_signal = None;
        }

        is_due
    }

    /// How long to wait for the next signal: `idle` when nothing is pending,
    /// otherwise until the pending snapshot falls due (never above `idle`).
    pub fn next_wait(&self, now: Instant, idle: Duration) -> Duration {
        let (Some(first), Some(last)) = (self.first_signal, self.last_signal) else {
            return idle;
        };

        let until_quiet = (last + self.quiet).saturating_duration_since(now);
        let until_cap = (first + self.max_delay).saturating_duration_since(now);

        until_quiet.min(until_cap).min(idle)
    }
}

/// Sends printer snapshots: POST {server}/api/printers with the device key.
pub struct PrinterReporter {
    config: Config,
    client: reqwest::Client,
    hostname: String,
}

impl PrinterReporter {
    pub fn new(config: Config, hostname: String) -> Result<Self> {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(10))
            .build()
            .context("Failed to create HTTP client for printer snapshots")?;

        Ok(Self {
            config,
            client,
            hostname,
        })
    }

    /// One attempt, no retries. A 404 means the server predates
    /// /api/printers and is only logged at debug.
    pub async fn send(&self, report: &PrinterReport, api_key: &str) -> RequestOutcome {
        let url = format!("{}/api/printers", self.config.base_url);

        let response = self
            .client
            .post(&url)
            .header("X-Agent-Key", api_key)
            .header("Accept", "application/json")
            .json(report)
            .send()
            .await;

        let status = match response {
            Ok(response) => response.status(),
            Err(e) => {
                warn!("Could not send printer snapshot: {}", e);
                return RequestOutcome::Inconclusive;
            }
        };

        if status == reqwest::StatusCode::NOT_FOUND {
            debug!("Server has no printers endpoint (404); snapshot skipped");
            return RequestOutcome::Inconclusive;
        }

        let outcome = RequestOutcome::from_status(status);
        match outcome {
            RequestOutcome::Success => debug!("Printer snapshot sent ({:?})", report.trigger),
            _ => warn!("Printer snapshot rejected ({})", status.as_u16()),
        }

        outcome
    }

    /// Snapshot at once, then after every change signal and whenever a
    /// metrics interval passes without one. Runs until the token is cancelled.
    pub async fn run(
        &self,
        api_key: String,
        mut changes: mpsc::Receiver<()>,
        collect: fn() -> SpoolerSnapshot,
        cancellation_token: CancellationToken,
        key_health: Arc<KeyHealth>,
        mut power_gate: PowerGate,
    ) {
        let interval = Duration::from_secs(self.config.metrics_interval);
        let mut trigger = SnapshotTrigger::Tick;
        let mut is_listening = true;
        let mut ready = power_gate.wait_until_allowed(&cancellation_token).await;

        while ready {
            self.collect_and_send(trigger, collect, &api_key, &key_health)
                .await;

            (trigger, ready) = tokio::select! {
                changed = changes.recv(), if is_listening => {
                    is_listening = changed.is_some();
                    if !is_listening {
                        warn!("Printer listener stopped; snapshots continue each interval");
                    }
                    let trigger = if is_listening { SnapshotTrigger::Change } else { SnapshotTrigger::Tick };
                    (trigger, power_gate.wait_until_allowed(&cancellation_token).await)
                },
                allowed = power_gate.tick(interval, &cancellation_token) => (SnapshotTrigger::Tick, allowed),
            };
        }

        debug!("Printer snapshot loop stopped");
    }

    async fn collect_and_send(
        &self,
        trigger: SnapshotTrigger,
        collect: fn() -> SpoolerSnapshot,
        api_key: &str,
        key_health: &KeyHealth,
    ) {
        let snapshot = match tokio::task::spawn_blocking(collect).await {
            Ok(snapshot) => snapshot,
            Err(e) => {
                error!("Printer snapshot collection failed: {}", e);
                return;
            }
        };

        let report = PrinterReport::new(&self.hostname, trigger, snapshot);
        key_health.record(self.send(&report, api_key).await);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn job() -> PrintJob {
        PrintJob {
            id: 7,
            document: "label.zpl".into(),
            user_name: "picker".into(),
            status: 0x10,
            status_text: None,
            submitted: Some("2026-10-07T09:15:30Z".into()),
            total_pages: 1,
            pages_printed: 0,
            size: 1234,
            position: 1,
            priority: 1,
        }
    }

    #[test]
    fn serialises_the_exact_payload_shape() {
        let report = PrinterReport {
            hostname: "PACK-01".into(),
            agent_version: "0.8.0".into(),
            trigger: SnapshotTrigger::Change,
            collected_at: "2026-10-07T09:15:31Z".into(),
            spooler_available: true,
            printers: vec![Printer {
                name: "Zebra GK420d - ZPL #2".into(),
                port_name: "USB001".into(),
                driver_name: "ZDesigner GK420d".into(),
                status: 0x80,
                attributes: 0x840,
                jobs_count: 1,
                jobs: vec![job()],
            }],
        };

        let expected = serde_json::json!({
            "hostname": "PACK-01",
            "agent_version": "0.8.0",
            "trigger": "change",
            "collected_at": "2026-10-07T09:15:31Z",
            "spooler_available": true,
            "printers": [{
                "name": "Zebra GK420d - ZPL #2",
                "port_name": "USB001",
                "driver_name": "ZDesigner GK420d",
                "status": 128,
                "attributes": 2112,
                "jobs_count": 1,
                "jobs": [{
                    "id": 7,
                    "document": "label.zpl",
                    "user_name": "picker",
                    "status": 16,
                    "status_text": null,
                    "submitted": "2026-10-07T09:15:30Z",
                    "total_pages": 1,
                    "pages_printed": 0,
                    "size": 1234,
                    "position": 1,
                    "priority": 1
                }]
            }]
        });

        assert_eq!(serde_json::to_value(&report).unwrap(), expected);
    }

    #[test]
    fn an_unavailable_spooler_sends_no_printers() {
        let report = PrinterReport::new(
            "PACK-01",
            SnapshotTrigger::Tick,
            SpoolerSnapshot::unavailable(),
        );
        let value = serde_json::to_value(&report).unwrap();

        assert_eq!(value["trigger"], "tick");
        assert_eq!(value["spooler_available"], false);
        assert_eq!(value["printers"], serde_json::json!([]));
        assert_eq!(value["agent_version"], AGENT_VERSION);
        assert!(value["collected_at"].as_str().unwrap().ends_with('Z'));
    }

    #[test]
    fn converts_systemtime_to_utc_iso() {
        assert_eq!(
            systemtime_to_iso(2026, 10, 7, 9, 15, 30, 250).as_deref(),
            Some("2026-10-07T09:15:30Z")
        );
        assert_eq!(
            systemtime_to_iso(2024, 2, 29, 23, 59, 59, 999).as_deref(),
            Some("2024-02-29T23:59:59Z")
        );
    }

    #[test]
    fn unset_or_invalid_systemtime_is_none() {
        assert_eq!(systemtime_to_iso(0, 0, 0, 0, 0, 0, 0), None);
        assert_eq!(systemtime_to_iso(2026, 13, 1, 0, 0, 0, 0), None);
        assert_eq!(systemtime_to_iso(2025, 2, 29, 0, 0, 0, 0), None);
        assert_eq!(systemtime_to_iso(2026, 10, 7, 24, 0, 0, 0), None);
    }

    fn debouncer() -> Debouncer {
        Debouncer::new(Duration::from_millis(1500), Duration::from_millis(5000))
    }

    fn ms(start: Instant, millis: u64) -> Instant {
        start + Duration::from_millis(millis)
    }

    #[test]
    fn nothing_is_due_without_signals() {
        let start = Instant::now();
        let mut debouncer = debouncer();

        assert!(!debouncer.take_due(ms(start, 60_000)));
        assert_eq!(
            debouncer.next_wait(start, Duration::from_secs(1)),
            Duration::from_secs(1)
        );
    }

    #[test]
    fn a_label_burst_collapses_to_one_snapshot() {
        let start = Instant::now();
        let mut debouncer = debouncer();
        let mut snapshots = 0;

        for at in [0, 120, 300, 450, 800, 1000] {
            debouncer.signal(ms(start, at));
            snapshots += usize::from(debouncer.take_due(ms(start, at)));
        }
        for at in (1100..10_000).step_by(100) {
            snapshots += usize::from(debouncer.take_due(ms(start, at)));
        }

        assert_eq!(snapshots, 1);
    }

    #[test]
    fn waits_for_the_quiet_period_after_the_last_signal() {
        let start = Instant::now();
        let mut debouncer = debouncer();
        debouncer.signal(ms(start, 0));
        debouncer.signal(ms(start, 1000));

        assert!(!debouncer.take_due(ms(start, 2499)));
        assert!(debouncer.is_pending());
        assert!(debouncer.take_due(ms(start, 2500)));
        assert!(!debouncer.is_pending());
    }

    #[test]
    fn a_flood_is_capped_at_the_max_delay() {
        let start = Instant::now();
        let mut debouncer = debouncer();
        let mut due_at = Vec::new();

        for at in (0..12_000).step_by(200) {
            debouncer.signal(ms(start, at));
            if debouncer.take_due(ms(start, at)) {
                due_at.push(at);
            }
        }

        assert_eq!(due_at, vec![5000, 10_200]);
    }

    #[test]
    fn next_wait_runs_to_whichever_deadline_is_first() {
        let start = Instant::now();
        let idle = Duration::from_secs(1);
        let mut debouncer = debouncer();
        debouncer.signal(ms(start, 0));

        assert_eq!(
            debouncer.next_wait(ms(start, 1000), idle),
            Duration::from_millis(500)
        );

        debouncer.signal(ms(start, 4500));
        assert_eq!(
            debouncer.next_wait(ms(start, 4800), idle),
            Duration::from_millis(200)
        );
        assert_eq!(debouncer.next_wait(ms(start, 6000), idle), Duration::ZERO);
    }

    #[test]
    fn next_wait_never_exceeds_the_idle_slice() {
        let start = Instant::now();
        let mut debouncer = Debouncer::new(Duration::from_secs(10), Duration::from_secs(30));
        debouncer.signal(start);

        assert_eq!(
            debouncer.next_wait(start, Duration::from_secs(1)),
            Duration::from_secs(1)
        );
    }
}
