//! Linux health: failed systemd units, whether a reboot is pending and how
//! many package updates are waiting.
//!
//! Strictly read-only. The only commands run are queries:
//!   `systemctl list-units --failed --plain --no-legend --no-pager`
//!   `apt-get -s -o Debug::NoLocking=1 upgrade`   (simulation, takes no lock)
//! Never `apt-get update` or anything else that writes. Each command runs
//! with a timeout and without blocking the async runtime; any failure only
//! leaves the matching field empty.

// Only Linux builds report health.
#![cfg_attr(not(target_os = "linux"), allow(dead_code))]

use chrono::{DateTime, Utc};
use serde::Serialize;
use std::path::Path;
use std::process::Stdio;
use std::time::{Duration, Instant};
use tracing::{debug, warn};

/// Exists while Debian/Ubuntu want a reboot (kernel, libc, ... updated).
pub const REBOOT_REQUIRED_FLAG: &str = "/var/run/reboot-required";

pub const FAILED_UNITS_COMMAND: (&str, &[&str]) = (
    "systemctl",
    &[
        "list-units",
        "--failed",
        "--plain",
        "--no-legend",
        "--no-pager",
    ],
);

pub const APT_SIMULATE_COMMAND: (&str, &[&str]) =
    ("apt-get", &["-s", "-o", "Debug::NoLocking=1", "upgrade"]);

/// `linux_health` in the metrics payload.
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct LinuxHealth {
    /// Omitted only when systemctl is unavailable.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub failed_units: Option<Vec<String>>,
    pub reboot_required: bool,
    pub pending_updates: Option<u64>,
    pub pending_security_updates: Option<u64>,
    pub checked_updates_at: Option<String>,
}

/// Failed unit names from `systemctl list-units --failed --plain
/// --no-legend`. Some versions still prefix failed rows with a status dot.
pub fn parse_failed_units(output: &str) -> Vec<String> {
    output
        .lines()
        .filter_map(|line| {
            line.split_whitespace()
                .find(|token| !matches!(*token, "●" | "*" | "×"))
                .map(str::to_string)
        })
        .collect()
}

/// Updates an `apt-get -s upgrade` simulation would install.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct AptCounts {
    pub pending: u64,
    pub security: u64,
}

pub fn parse_apt_simulation(output: &str) -> AptCounts {
    let installs: Vec<&str> = output
        .lines()
        .filter(|line| line.starts_with("Inst "))
        .collect();

    AptCounts {
        pending: installs.len() as u64,
        security: installs
            .iter()
            .filter(|line| line.contains("-security"))
            .count() as u64,
    }
}

/// Last apt simulation, reused between submissions so apt runs at most once
/// per interval.
#[derive(Debug, Clone, Default)]
pub struct AptCache {
    checked_at: Option<Instant>,
    checked_at_wall: Option<DateTime<Utc>>,
    counts: Option<AptCounts>,
}

impl AptCache {
    /// Whether apt should be asked again.
    pub fn is_due(&self, now: Instant, interval: Duration) -> bool {
        match self.checked_at {
            None => true,
            Some(checked) => now.saturating_duration_since(checked) >= interval,
        }
    }

    /// Store a check's outcome (None: apt missing or the run failed). A
    /// failure is remembered too, so a broken apt is retried next interval,
    /// not every minute.
    pub fn record(&mut self, now: Instant, wall: DateTime<Utc>, counts: Option<AptCounts>) {
        self.checked_at = Some(now);
        self.checked_at_wall = counts.map(|_| wall);
        self.counts = counts;
    }

    pub fn counts(&self) -> Option<AptCounts> {
        self.counts
    }

    pub fn checked_at(&self) -> Option<DateTime<Utc>> {
        self.checked_at_wall
    }
}

/// Run a read-only query command. None if it is missing, fails, exits
/// non-zero or outlives `timeout` (then it is killed).
pub async fn run_query(program: &str, args: &[&str], timeout: Duration) -> Option<String> {
    let mut command = tokio::process::Command::new(program);
    command
        .args(args)
        .env("LC_ALL", "C")
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .kill_on_drop(true);

    match tokio::time::timeout(timeout, command.output()).await {
        Ok(Ok(output)) if output.status.success() => {
            Some(String::from_utf8_lossy(&output.stdout).into_owned())
        }
        Ok(Ok(output)) => {
            debug!("{} exited with {}", program, output.status);
            None
        }
        Ok(Err(e)) => {
            debug!("{} unavailable: {}", program, e);
            None
        }
        Err(_) => {
            warn!(
                "{} took longer than {}s; skipped",
                program,
                timeout.as_secs()
            );
            None
        }
    }
}

/// Collects `LinuxHealth`, keeping the apt result between calls.
#[derive(Debug, Default)]
pub struct HealthCollector {
    apt: AptCache,
}

impl HealthCollector {
    pub async fn collect(
        &mut self,
        command_timeout: Duration,
        apt_interval: Duration,
    ) -> LinuxHealth {
        let (program, args) = FAILED_UNITS_COMMAND;
        let failed_units = run_query(program, args, command_timeout)
            .await
            .map(|output| parse_failed_units(&output));

        if self.apt.is_due(Instant::now(), apt_interval) {
            let (program, args) = APT_SIMULATE_COMMAND;
            let counts = run_query(program, args, command_timeout)
                .await
                .map(|output| parse_apt_simulation(&output));
            self.apt.record(Instant::now(), Utc::now(), counts);
        }

        LinuxHealth {
            failed_units,
            reboot_required: Path::new(REBOOT_REQUIRED_FLAG).exists(),
            pending_updates: self.apt.counts().map(|counts| counts.pending),
            pending_security_updates: self.apt.counts().map(|counts| counts.security),
            checked_updates_at: self.apt.checked_at().map(|at| at.to_rfc3339()),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_failed_units() {
        let output = "\
nginx.service loaded failed failed A high performance web server
● smartd.service loaded failed failed Self Monitoring and Reporting Technology
";
        assert_eq!(
            parse_failed_units(output),
            vec!["nginx.service".to_string(), "smartd.service".to_string()]
        );
        assert!(parse_failed_units("").is_empty());
        assert!(parse_failed_units("\n  \n").is_empty());
    }

    #[test]
    fn counts_pending_and_security_updates() {
        let output = "\
NOTE: This is only a simulation!
Reading package lists...
The following packages will be upgraded:
  libc6 openssl tzdata
3 upgraded, 0 newly installed, 0 to remove and 0 not upgraded.
Inst libc6 [2.41-12] (2.41-12+deb13u1 Debian:13.1/stable [amd64])
Inst openssl [3.5.1-1] (3.5.1-1+deb13u1 Debian-Security:13/stable-security [amd64])
Inst tzdata [2025b-4] (2025b-4+deb13u1 Debian:13.1/stable-updates [all])
Conf libc6 (2.41-12+deb13u1 Debian:13.1/stable [amd64])
Conf openssl (3.5.1-1+deb13u1 Debian-Security:13/stable-security [amd64])
";
        assert_eq!(
            parse_apt_simulation(output),
            AptCounts {
                pending: 3,
                security: 1
            }
        );
        assert_eq!(
            parse_apt_simulation(
                "0 upgraded, 0 newly installed, 0 to remove and 0 not upgraded.\n"
            ),
            AptCounts {
                pending: 0,
                security: 0
            }
        );
    }

    #[test]
    fn checks_apt_at_most_once_per_interval() {
        let interval = Duration::from_secs(3600);
        let start = Instant::now();
        let mut cache = AptCache::default();

        assert!(cache.is_due(start, interval));
        cache.record(
            start,
            Utc::now(),
            Some(AptCounts {
                pending: 4,
                security: 2,
            }),
        );

        assert!(!cache.is_due(start + Duration::from_secs(60), interval));
        assert!(!cache.is_due(start + Duration::from_secs(3599), interval));
        assert!(cache.is_due(start + interval, interval));
        assert_eq!(cache.counts().unwrap().pending, 4);
        assert!(cache.checked_at().is_some());
    }

    #[test]
    fn a_failed_apt_check_clears_the_counts_and_waits_for_the_next_interval() {
        let interval = Duration::from_secs(3600);
        let start = Instant::now();
        let mut cache = AptCache::default();
        cache.record(
            start,
            Utc::now(),
            Some(AptCounts {
                pending: 1,
                security: 0,
            }),
        );

        cache.record(start + interval, Utc::now(), None);

        assert_eq!(cache.counts(), None);
        assert_eq!(cache.checked_at(), None);
        assert!(!cache.is_due(start + interval + Duration::from_secs(60), interval));
    }

    #[test]
    fn serialises_health_with_and_without_optional_fields() {
        let health = LinuxHealth {
            failed_units: Some(vec!["nginx.service".to_string()]),
            reboot_required: true,
            pending_updates: Some(3),
            pending_security_updates: Some(1),
            checked_updates_at: Some("2026-10-03T10:00:00+00:00".to_string()),
        };
        assert_eq!(
            serde_json::to_value(&health).unwrap(),
            serde_json::json!({
                "failed_units": ["nginx.service"],
                "reboot_required": true,
                "pending_updates": 3,
                "pending_security_updates": 1,
                "checked_updates_at": "2026-10-03T10:00:00+00:00"
            })
        );

        let unknown = LinuxHealth {
            failed_units: None,
            reboot_required: false,
            pending_updates: None,
            pending_security_updates: None,
            checked_updates_at: None,
        };
        assert_eq!(
            serde_json::to_value(&unknown).unwrap(),
            serde_json::json!({
                "reboot_required": false,
                "pending_updates": null,
                "pending_security_updates": null,
                "checked_updates_at": null
            })
        );
    }

    #[tokio::test]
    async fn missing_commands_give_none() {
        assert_eq!(
            run_query("benjh-rmm-no-such-command", &[], Duration::from_secs(5)).await,
            None
        );
    }

    #[cfg(unix)]
    #[tokio::test]
    async fn slow_commands_are_cut_off() {
        let started = Instant::now();
        assert_eq!(
            run_query("sleep", &["30"], Duration::from_millis(200)).await,
            None
        );
        assert!(started.elapsed() < Duration::from_secs(5));
    }

    #[cfg(unix)]
    #[tokio::test]
    async fn reads_command_output() {
        assert_eq!(
            run_query("echo", &["hello"], Duration::from_secs(5)).await,
            Some("hello\n".to_string())
        );
        assert_eq!(run_query("false", &[], Duration::from_secs(5)).await, None);
    }
}
