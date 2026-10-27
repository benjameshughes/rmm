//! Linux security report: pending updates, unattended-upgrades, SSH
//! hardening and authorized keys, the firewall, failed SSH logins and
//! listening ports. Sent with the metrics once per security report interval.
//!
//! Strictly read-only. The agent only reads files and runs fixed queries:
//!   `apt-get -s -o Debug::NoLocking=1 upgrade`   (simulation, takes no lock)
//!   `apt-config dump`
//!   `sshd -T`                                     (prints the effective config)
//!   `nft -j list ruleset`
//!   `ufw status`
//!   `journalctl -q --no-pager -o cat --since -24h -t sshd -t sshd-session`
//!   `ss -tulpnH`
//! Nothing here takes input from the server. Every reading fails soft: a
//! missing tool or unreadable file leaves only its own field null.

// Only Linux builds report security.
#![cfg_attr(not(target_os = "linux"), allow(dead_code))]

use base64::engine::general_purpose::{STANDARD, STANDARD_NO_PAD};
use base64::Engine;
use chrono::{
    DateTime, Datelike, Duration as ChronoDuration, Local, NaiveDateTime, SecondsFormat, TimeZone,
    Utc,
};
use serde::Serialize;
use sha2::{Digest, Sha256};
use std::collections::HashMap;
use std::fs;
use std::net::IpAddr;
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant, SystemTime};

use crate::linux_health::{
    parse_apt_simulation, run_query, APT_SIMULATE_COMMAND, REBOOT_REQUIRED_FLAG,
};

/// Lists the packages that asked for the pending reboot.
pub const REBOOT_REQUIRED_PACKAGES: &str = "/var/run/reboot-required.pkgs";

pub const UNATTENDED_UPGRADE_BINARY: &str = "/usr/bin/unattended-upgrade";

/// Both are written each time unattended-upgrades runs; the newer one wins.
pub const UNATTENDED_UPGRADE_RUN_MARKERS: [&str; 2] = [
    "/var/log/unattended-upgrades/unattended-upgrades.log",
    "/var/lib/apt/periodic/unattended-upgrades-stamp",
];

pub const SSHD_CONFIG: &str = "/etc/ssh/sshd_config";
pub const PASSWD: &str = "/etc/passwd";
pub const AUTH_LOG: &str = "/var/log/auth.log";

pub const APT_CONFIG_COMMAND: (&str, &[&str]) = ("apt-config", &["dump"]);
pub const SSHD_TEST_COMMAND: (&str, &[&str]) = ("sshd", &["-T"]);
pub const NFT_RULESET_COMMAND: (&str, &[&str]) = ("nft", &["-j", "list", "ruleset"]);
pub const UFW_STATUS_COMMAND: (&str, &[&str]) = ("ufw", &["status"]);
pub const LISTENING_PORTS_COMMAND: (&str, &[&str]) = ("ss", &["-tulpnH"]);
pub const SSH_JOURNAL_COMMAND: (&str, &[&str]) = (
    "journalctl",
    &[
        "-q",
        "--no-pager",
        "-o",
        "cat",
        "--since",
        "-24h",
        "-t",
        "sshd",
        "-t",
        "sshd-session",
    ],
);

/// Hours of failed SSH logins counted.
pub const FAILED_LOGIN_WINDOW_HOURS: i64 = 24;
/// Source addresses named in the report.
pub const FAILED_LOGIN_TOP_SOURCES: usize = 5;
/// Human accounts start at this uid (Debian's FIRST_UID).
pub const FIRST_HUMAN_UID: u32 = 1000;
/// `nobody`.
pub const NOBODY_UID: u32 = 65534;
pub const MAX_AUTHORIZED_KEYS: usize = 200;
pub const MAX_LISTENING_PORTS: usize = 500;
/// Include depth followed in sshd_config, matching sshd's own limit.
pub const SSHD_INCLUDE_DEPTH: usize = 16;

/// `linux_security` in the metrics payload.
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct LinuxSecurity {
    pub collected_at: String,
    pub updates: Updates,
    pub unattended_upgrades: UnattendedUpgrades,
    /// None when the box has no SSH server.
    pub ssh: Option<SshSettings>,
    /// None when /etc/passwd could not be read.
    pub authorized_keys: Option<Vec<AuthorizedKey>>,
    pub firewall: Firewall,
    /// None when neither the journal nor auth.log could be read.
    pub failed_logins: Option<FailedLogins>,
    /// None when `ss` is unavailable.
    pub listening_ports: Option<Vec<ListeningPort>>,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct Updates {
    pub pending: Option<u64>,
    pub security: Option<u64>,
    pub reboot_required: bool,
    pub reboot_packages: Vec<String>,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct UnattendedUpgrades {
    pub installed: bool,
    /// APT::Periodic::Unattended-Upgrade; None when apt-config is unavailable.
    pub enabled: Option<bool>,
    pub last_run_at: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct SshSettings {
    /// "sshd -T" or "sshd_config".
    pub source: String,
    pub permit_root_login: Option<String>,
    pub password_authentication: Option<bool>,
    pub kbd_interactive_authentication: Option<bool>,
}

/// One authorized_keys entry. The key itself never leaves the box.
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct AuthorizedKey {
    pub user: String,
    pub key_type: String,
    pub comment: Option<String>,
    pub fingerprint: String,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct Firewall {
    pub nftables_active: Option<bool>,
    pub nftables_table_count: Option<u64>,
    pub nftables_rule_count: Option<u64>,
    /// None when ufw is not installed.
    pub ufw_active: Option<bool>,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct FailedLogins {
    /// "journal" or "auth.log".
    pub source: String,
    pub window_hours: i64,
    pub count: u64,
    pub top_sources: Vec<LoginSource>,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct LoginSource {
    pub ip: String,
    pub count: u64,
}

/// Mirrors the Windows inventory's `listening_ports`, plus the protocol.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct ListeningPort {
    pub protocol: String,
    pub address: String,
    pub port: u16,
    pub process_name: Option<String>,
    pub process_id: Option<u32>,
}

/// When the last report was delivered, so one goes out per interval.
#[derive(Debug, Default)]
pub struct ReportSchedule {
    sent_at: Option<Instant>,
}

impl ReportSchedule {
    pub fn is_due(&self, now: Instant, interval: Duration) -> bool {
        match self.sent_at {
            None => true,
            Some(sent) => now.saturating_duration_since(sent) >= interval,
        }
    }

    pub fn mark_sent(&mut self, now: Instant) {
        self.sent_at = Some(now);
    }
}

/// Collects the whole report. Every reading is independent.
pub async fn collect(command_timeout: Duration) -> LinuxSecurity {
    let query = |(program, args): (&'static str, &'static [&'static str])| {
        run_query(program, args, command_timeout)
    };

    let apt = query(APT_SIMULATE_COMMAND)
        .await
        .map(|output| parse_apt_simulation(&output));
    let apt_config = query(APT_CONFIG_COMMAND).await;
    let sshd_test = query(SSHD_TEST_COMMAND).await;
    let nft = query(NFT_RULESET_COMMAND).await;
    let ufw = query(UFW_STATUS_COMMAND).await;
    let journal = query(SSH_JOURNAL_COMMAND).await;
    let sockets = query(LISTENING_PORTS_COMMAND).await;

    let nftables = nft.as_deref().and_then(parse_nft_ruleset);

    LinuxSecurity {
        collected_at: Utc::now().to_rfc3339_opts(SecondsFormat::Secs, true),
        updates: Updates {
            pending: apt.map(|counts| counts.pending),
            security: apt.map(|counts| counts.security),
            reboot_required: Path::new(REBOOT_REQUIRED_FLAG).exists(),
            reboot_packages: read(REBOOT_REQUIRED_PACKAGES)
                .map(|text| parse_reboot_packages(&text))
                .unwrap_or_default(),
        },
        unattended_upgrades: UnattendedUpgrades {
            installed: Path::new(UNATTENDED_UPGRADE_BINARY).exists(),
            enabled: apt_config.map(|output| parse_unattended_upgrade_enabled(&output)),
            last_run_at: unattended_upgrade_last_run(),
        },
        ssh: match sshd_test {
            Some(output) => Some(parse_sshd_test(&output)),
            None => {
                sshd_config_lines(Path::new(SSHD_CONFIG), 0).map(|lines| parse_sshd_config(&lines))
            }
        },
        authorized_keys: read(PASSWD).map(|passwd| authorized_keys(&passwd)),
        firewall: Firewall {
            nftables_active: nftables.map(|ruleset| ruleset.is_active),
            nftables_table_count: nftables.map(|ruleset| ruleset.tables),
            nftables_rule_count: nftables.map(|ruleset| ruleset.rules),
            ufw_active: ufw.and_then(|output| parse_ufw_status(&output)),
        },
        failed_logins: failed_logins(journal),
        listening_ports: sockets.map(|output| parse_listening_ports(&output)),
    }
}

fn read(path: impl AsRef<Path>) -> Option<String> {
    fs::read_to_string(path).ok()
}

pub fn parse_reboot_packages(text: &str) -> Vec<String> {
    let mut packages: Vec<String> = text
        .lines()
        .map(str::trim)
        .filter(|line| !line.is_empty())
        .map(str::to_string)
        .collect();
    packages.dedup();
    packages
}

/// `APT::Periodic::Unattended-Upgrade "1";` from `apt-config dump`. Unset
/// counts as off, which is what apt does.
pub fn parse_unattended_upgrade_enabled(output: &str) -> bool {
    output
        .lines()
        .filter_map(|line| {
            line.trim()
                .strip_prefix("APT::Periodic::Unattended-Upgrade ")
        })
        .last()
        .map(|value| value.trim().trim_end_matches(';').trim_matches('"'))
        .is_some_and(|value| !value.is_empty() && value != "0")
}

fn unattended_upgrade_last_run() -> Option<String> {
    UNATTENDED_UPGRADE_RUN_MARKERS
        .iter()
        .filter_map(|path| {
            fs::metadata(path)
                .and_then(|metadata| metadata.modified())
                .ok()
        })
        .max()
        .map(|modified: SystemTime| {
            DateTime::<Utc>::from(modified).to_rfc3339_opts(SecondsFormat::Secs, true)
        })
}

fn yes_no(value: &str) -> Option<bool> {
    match value.to_ascii_lowercase().as_str() {
        "yes" => Some(true),
        "no" => Some(false),
        _ => None,
    }
}

/// `sshd -T` prints every effective setting as lowercase `key value`.
pub fn parse_sshd_test(output: &str) -> SshSettings {
    let settings: HashMap<String, String> = output
        .lines()
        .filter_map(|line| line.trim().split_once(' '))
        .map(|(key, value)| (key.to_ascii_lowercase(), value.trim().to_string()))
        .collect();

    SshSettings {
        source: "sshd -T".to_string(),
        permit_root_login: settings.get("permitrootlogin").cloned(),
        password_authentication: settings
            .get("passwordauthentication")
            .and_then(|v| yes_no(v)),
        kbd_interactive_authentication: settings
            .get("kbdinteractiveauthentication")
            .or_else(|| settings.get("challengeresponseauthentication"))
            .and_then(|v| yes_no(v)),
    }
}

/// sshd_config lines with every Include expanded in place, or None when the
/// main file cannot be read (no SSH server).
fn sshd_config_lines(path: &Path, depth: usize) -> Option<Vec<String>> {
    let text = read(path)?;
    let mut lines = Vec::new();

    for line in text.lines() {
        let trimmed = line.trim();
        let include = trimmed
            .split_once(|c: char| c.is_whitespace() || c == '=')
            .filter(|(keyword, _)| keyword.eq_ignore_ascii_case("include"));

        match include {
            Some((_, patterns)) if depth < SSHD_INCLUDE_DEPTH => {
                for pattern in patterns.split_whitespace() {
                    for included in expand_include(pattern) {
                        lines.extend(sshd_config_lines(&included, depth + 1).unwrap_or_default());
                    }
                }
            }
            _ => lines.push(trimmed.to_string()),
        }
    }

    Some(lines)
}

/// An Include pattern: relative paths are under /etc/ssh, and one `*` in the
/// file name matches like sshd's glob. Sorted, as sshd reads them.
fn expand_include(pattern: &str) -> Vec<PathBuf> {
    let path = if pattern.starts_with('/') {
        PathBuf::from(pattern)
    } else {
        Path::new("/etc/ssh").join(pattern)
    };

    let Some(name) = path.file_name().and_then(|name| name.to_str()) else {
        return Vec::new();
    };
    let Some((prefix, suffix)) = name.split_once('*') else {
        return vec![path.clone()];
    };
    let Some(directory) = path.parent() else {
        return Vec::new();
    };

    let mut matches: Vec<PathBuf> = fs::read_dir(directory)
        .map(|entries| {
            entries
                .filter_map(Result::ok)
                .map(|entry| entry.path())
                .filter(|candidate| {
                    candidate
                        .file_name()
                        .and_then(|file| file.to_str())
                        .is_some_and(|file| {
                            file.starts_with(prefix)
                                && file.ends_with(suffix)
                                && file.len() >= prefix.len() + suffix.len()
                        })
                })
                .collect()
        })
        .unwrap_or_default();
    matches.sort();
    matches
}

/// The global settings from sshd_config lines (Includes already expanded).
/// sshd keeps the first value it reads and Match blocks are per-connection,
/// so reading stops at the first Match. Unset settings take sshd's defaults.
pub fn parse_sshd_config(lines: &[String]) -> SshSettings {
    let mut settings: HashMap<String, String> = HashMap::new();

    for line in lines {
        let line = line.trim();
        if line.is_empty() || line.starts_with('#') {
            continue;
        }
        let Some((key, value)) = line.split_once(|c: char| c.is_whitespace() || c == '=') else {
            continue;
        };
        let key = key.to_ascii_lowercase();
        if key == "match" {
            break;
        }
        settings
            .entry(key)
            .or_insert_with(|| value.trim().trim_start_matches('=').trim().to_string());
    }

    let setting = |key: &str, default: &str| {
        settings
            .get(key)
            .cloned()
            .unwrap_or_else(|| default.to_string())
    };

    SshSettings {
        source: "sshd_config".to_string(),
        permit_root_login: Some(
            setting("permitrootlogin", "prohibit-password").to_ascii_lowercase(),
        ),
        password_authentication: yes_no(&setting("passwordauthentication", "yes")),
        kbd_interactive_authentication: yes_no(
            &settings
                .get("kbdinteractiveauthentication")
                .or_else(|| settings.get("challengeresponseauthentication"))
                .cloned()
                .unwrap_or_else(|| "yes".to_string()),
        ),
    }
}

/// root and the human accounts (uid 1000 up, not nobody) from /etc/passwd,
/// with their home directories.
pub fn key_holders(passwd: &str) -> Vec<(String, PathBuf)> {
    passwd
        .lines()
        .filter_map(|line| {
            let fields: Vec<&str> = line.split(':').collect();
            let uid: u32 = fields.get(2)?.parse().ok()?;
            let home = fields.get(5).filter(|home| !home.is_empty())?;
            let is_holder = uid == 0 || (uid >= FIRST_HUMAN_UID && uid != NOBODY_UID);
            is_holder.then(|| (fields[0].to_string(), PathBuf::from(home)))
        })
        .collect()
}

fn authorized_keys(passwd: &str) -> Vec<AuthorizedKey> {
    key_holders(passwd)
        .into_iter()
        .flat_map(|(user, home)| {
            read(home.join(".ssh/authorized_keys"))
                .map(|text| parse_authorized_keys(&user, &text))
                .unwrap_or_default()
        })
        .take(MAX_AUTHORIZED_KEYS)
        .collect()
}

fn is_key_type(token: &str) -> bool {
    ["ssh-", "ecdsa-sha2-", "sk-ssh-", "sk-ecdsa-sha2-"]
        .iter()
        .any(|prefix| token.starts_with(prefix))
}

/// Type, comment and SHA256 fingerprint (as `ssh-keygen -l` prints it) of
/// each key. Leading options such as `from=` or `command=` are skipped.
pub fn parse_authorized_keys(user: &str, text: &str) -> Vec<AuthorizedKey> {
    text.lines()
        .map(str::trim)
        .filter(|line| !line.is_empty() && !line.starts_with('#'))
        .filter_map(|line| {
            let tokens: Vec<&str> = line.split_whitespace().collect();
            let (index, blob) = tokens.windows(2).enumerate().find_map(|(index, pair)| {
                is_key_type(pair[0])
                    .then(|| STANDARD.decode(pair[1]).ok())
                    .flatten()
                    .map(|blob| (index, blob))
            })?;
            let comment = tokens[index + 2..].join(" ");

            Some(AuthorizedKey {
                user: user.to_string(),
                key_type: tokens[index].to_string(),
                comment: (!comment.is_empty()).then_some(comment),
                fingerprint: format!("SHA256:{}", STANDARD_NO_PAD.encode(Sha256::digest(&blob))),
            })
        })
        .collect()
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct NftRuleset {
    /// At least one base chain is hooked into netfilter.
    pub is_active: bool,
    pub tables: u64,
    pub rules: u64,
}

/// `nft -j list ruleset`: a flat `nftables` list of table, chain, rule, set
/// ... objects.
pub fn parse_nft_ruleset(output: &str) -> Option<NftRuleset> {
    let json: serde_json::Value = serde_json::from_str(output).ok()?;
    let objects = json.get("nftables")?.as_array()?;
    let count = |kind: &str| {
        objects
            .iter()
            .filter(|object| object.get(kind).is_some())
            .count() as u64
    };

    Some(NftRuleset {
        is_active: objects
            .iter()
            .filter_map(|object| object.get("chain"))
            .any(|chain| chain.get("hook").is_some()),
        tables: count("table"),
        rules: count("rule"),
    })
}

/// `Status: active` or `Status: inactive`.
pub fn parse_ufw_status(output: &str) -> Option<bool> {
    output
        .lines()
        .find_map(|line| line.trim().strip_prefix("Status:"))
        .map(|status| status.trim() == "active")
}

/// The failed SSH logins in the window: from the journal, or auth.log when
/// journalctl is missing or failed.
fn failed_logins(journal: Option<String>) -> Option<FailedLogins> {
    if let Some(output) = journal {
        return Some(count_failed_logins("journal", output.lines()));
    }

    let text = read(AUTH_LOG)?;
    let since = Utc::now() - ChronoDuration::hours(FAILED_LOGIN_WINDOW_HOURS);
    let messages = auth_log_ssh_messages(&text, since, Local::now().year());

    Some(count_failed_logins("auth.log", messages.into_iter()))
}

/// One count per failed attempt: each `Failed <method> for ...` line, plus
/// each `Invalid user ... from` line. A password tried for an invalid user
/// logs both, so its `Failed ... for invalid user` line is not counted again.
pub fn count_failed_logins<'a>(
    source: &str,
    messages: impl Iterator<Item = &'a str>,
) -> FailedLogins {
    let mut count = 0;
    let mut by_source: HashMap<String, u64> = HashMap::new();

    for message in messages {
        let message = message.trim();
        let is_failure = message.starts_with("Invalid user ")
            || (message.starts_with("Failed ") && !message.contains(" for invalid user "));
        if !is_failure {
            continue;
        }

        count += 1;
        if let Some(ip) = source_address(message) {
            *by_source.entry(ip).or_default() += 1;
        }
    }

    let mut top_sources: Vec<LoginSource> = by_source
        .into_iter()
        .map(|(ip, count)| LoginSource { ip, count })
        .collect();
    top_sources.sort_by(|a, b| b.count.cmp(&a.count).then_with(|| a.ip.cmp(&b.ip)));
    top_sources.truncate(FAILED_LOGIN_TOP_SOURCES);

    FailedLogins {
        source: source.to_string(),
        window_hours: FAILED_LOGIN_WINDOW_HOURS,
        count,
        top_sources,
    }
}

/// The address after the last ` from `, when it is an IP address.
fn source_address(message: &str) -> Option<String> {
    let (_, rest) = message.rsplit_once(" from ")?;
    let token = rest.split_whitespace().next()?;
    token.parse::<IpAddr>().ok().map(|ip| ip.to_string())
}

/// The sshd messages logged since `since` from auth.log, which stamps lines
/// either RFC 3339 (`2026-10-27T09:15:02.123456+00:00 host sshd[1]: ...`) or
/// the classic local `Oct 27 09:15:02 host sshd[1]: ...` without a year.
pub fn auth_log_ssh_messages(text: &str, since: DateTime<Utc>, year: i32) -> Vec<&str> {
    text.lines()
        .filter_map(|line| {
            let (stamp, rest) = match DateTime::parse_from_rfc3339(line.split_whitespace().next()?)
            {
                Ok(stamp) => (stamp.with_timezone(&Utc), line.splitn(2, ' ').nth(1)?),
                Err(_) => {
                    let classic = line.get(..15)?;
                    let naive = NaiveDateTime::parse_from_str(
                        &format!("{year} {classic}"),
                        "%Y %b %e %H:%M:%S",
                    )
                    .ok()?;
                    (
                        Local
                            .from_local_datetime(&naive)
                            .earliest()?
                            .with_timezone(&Utc),
                        line.get(16..)?,
                    )
                }
            };
            let (process, message) = rest.split_once(": ")?;
            let is_sshd = process
                .split_whitespace()
                .last()
                .is_some_and(|name| name.starts_with("sshd[") || name.starts_with("sshd-session["));

            (stamp >= since && is_sshd).then_some(message)
        })
        .collect()
}

/// `ss -tulpnH`: Netid State Recv-Q Send-Q Local Peer [Process]. Duplicate
/// sockets (SO_REUSEPORT workers) are listed once.
pub fn parse_listening_ports(output: &str) -> Vec<ListeningPort> {
    let mut ports: Vec<ListeningPort> = Vec::new();

    for line in output.lines() {
        let columns: Vec<&str> = line.split_whitespace().collect();
        let (Some(protocol), Some(local)) = (columns.first(), columns.get(4)) else {
            continue;
        };
        let Some((address, port)) = local.rsplit_once(':') else {
            continue;
        };
        let Ok(port) = port.parse::<u16>() else {
            continue;
        };
        let process = columns.get(6).copied().unwrap_or_default();

        let listener = ListeningPort {
            protocol: protocol.to_string(),
            address: address.trim_start_matches('[').replace(']', ""),
            port,
            process_name: process
                .split_once("((\"")
                .and_then(|(_, rest)| rest.split_once('"'))
                .map(|(name, _)| name.to_string()),
            process_id: process
                .split_once("pid=")
                .and_then(|(_, rest)| rest.split(|c: char| !c.is_ascii_digit()).next())
                .and_then(|pid| pid.parse().ok()),
        };

        if !ports.contains(&listener) {
            ports.push(listener);
        }
    }

    ports.truncate(MAX_LISTENING_PORTS);
    ports
}

#[cfg(test)]
mod tests {
    use super::*;

    const SSHD_T: &str = "\
port 22
addressfamily any
permitrootlogin without-password
passwordauthentication no
kbdinteractiveauthentication no
pubkeyauthentication yes
";

    #[test]
    fn reads_effective_settings_from_sshd_t() {
        assert_eq!(
            parse_sshd_test(SSHD_T),
            SshSettings {
                source: "sshd -T".into(),
                permit_root_login: Some("without-password".into()),
                password_authentication: Some(false),
                kbd_interactive_authentication: Some(false),
            }
        );
    }

    #[test]
    fn older_sshd_t_names_keyboard_interactive_challenge_response() {
        let settings = parse_sshd_test("permitrootlogin yes\npasswordauthentication yes\nchallengeresponseauthentication yes\n");
        assert_eq!(settings.permit_root_login.as_deref(), Some("yes"));
        assert_eq!(settings.password_authentication, Some(true));
        assert_eq!(settings.kbd_interactive_authentication, Some(true));
    }

    #[test]
    fn sshd_config_keeps_the_first_value_and_ignores_match_blocks() {
        let lines: Vec<String> = "\
# PermitRootLogin yes
PasswordAuthentication no
passwordauthentication yes
KbdInteractiveAuthentication=no
Match User backup
    PermitRootLogin yes
"
        .lines()
        .map(str::to_string)
        .collect();

        assert_eq!(
            parse_sshd_config(&lines),
            SshSettings {
                source: "sshd_config".into(),
                permit_root_login: Some("prohibit-password".into()),
                password_authentication: Some(false),
                kbd_interactive_authentication: Some(false),
            }
        );
    }

    #[test]
    fn an_empty_sshd_config_takes_sshds_defaults() {
        let settings = parse_sshd_config(&[]);
        assert_eq!(
            settings.permit_root_login.as_deref(),
            Some("prohibit-password")
        );
        assert_eq!(settings.password_authentication, Some(true));
        assert_eq!(settings.kbd_interactive_authentication, Some(true));
    }

    #[test]
    fn counts_pending_and_security_updates_from_apt_simulation() {
        let counts = parse_apt_simulation(
            "Inst openssl [3.5.1-1] (3.5.1-1+deb13u1 Debian-Security:13/stable-security [amd64])\n\
             Inst tzdata [2025b-4] (2025b-4+deb13u1 Debian:13.1/stable-updates [all])\n",
        );
        assert_eq!((counts.pending, counts.security), (2, 1));
    }

    #[test]
    fn reads_reboot_packages_and_the_unattended_upgrade_switch() {
        assert_eq!(
            parse_reboot_packages("linux-image-amd64\nlibc6\nlibc6\n\n"),
            vec!["linux-image-amd64", "libc6"]
        );

        let dump = "APT::Architecture \"amd64\";\nAPT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"1\";\n";
        assert!(parse_unattended_upgrade_enabled(dump));
        assert!(!parse_unattended_upgrade_enabled(
            "APT::Periodic::Unattended-Upgrade \"0\";\n"
        ));
        assert!(!parse_unattended_upgrade_enabled(
            "APT::Architecture \"amd64\";\n"
        ));
    }

    #[test]
    fn picks_root_and_human_accounts_from_passwd() {
        let passwd = "\
root:x:0:0:root:/root:/bin/bash
daemon:x:1:1:daemon:/usr/sbin:/usr/sbin/nologin
ben:x:1000:1000:Ben,,,:/home/ben:/bin/bash
nobody:x:65534:65534:nobody:/nonexistent:/usr/sbin/nologin
";
        assert_eq!(
            key_holders(passwd),
            vec![
                ("root".into(), PathBuf::from("/root")),
                ("ben".into(), PathBuf::from("/home/ben"))
            ]
        );
    }

    #[test]
    fn fingerprints_authorized_keys_without_sending_the_key() {
        let blob = "AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl";
        let text = format!(
            "# deploy keys\nssh-ed25519 {blob} ben@laptop\nfrom=\"10.0.0.0/8\",no-pty ssh-ed25519 {blob}\nnot a key\n"
        );

        let keys = parse_authorized_keys("ben", &text);

        assert_eq!(keys.len(), 2);
        assert_eq!(keys[0].key_type, "ssh-ed25519");
        assert_eq!(keys[0].comment.as_deref(), Some("ben@laptop"));
        assert_eq!(keys[1].comment, None);
        assert_eq!(keys[0].fingerprint, keys[1].fingerprint);
        assert_eq!(
            keys[0].fingerprint,
            "SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU"
        );
        assert!(!serde_json::to_string(&keys).unwrap().contains(blob));
    }

    #[test]
    fn reads_the_nft_ruleset() {
        let ruleset = r#"{"nftables": [
            {"metainfo": {"version": "1.1.3", "json_schema_version": 1}},
            {"table": {"family": "inet", "name": "filter", "handle": 1}},
            {"chain": {"family": "inet", "table": "filter", "name": "input", "type": "filter", "hook": "input", "prio": 0, "policy": "drop"}},
            {"chain": {"family": "inet", "table": "filter", "name": "ssh", "handle": 4}},
            {"rule": {"family": "inet", "table": "filter", "chain": "input", "expr": []}},
            {"rule": {"family": "inet", "table": "filter", "chain": "input", "expr": []}}
        ]}"#;
        assert_eq!(
            parse_nft_ruleset(ruleset),
            Some(NftRuleset {
                is_active: true,
                tables: 1,
                rules: 2
            })
        );

        let empty = r#"{"nftables": [{"metainfo": {"version": "1.1.3"}}]}"#;
        assert_eq!(
            parse_nft_ruleset(empty),
            Some(NftRuleset {
                is_active: false,
                tables: 0,
                rules: 0
            })
        );
        assert_eq!(parse_nft_ruleset("Error: Operation not permitted"), None);
    }

    #[test]
    fn reads_ufw_status() {
        assert_eq!(
            parse_ufw_status("Status: active\n\nTo Action From\n"),
            Some(true)
        );
        assert_eq!(parse_ufw_status("Status: inactive\n"), Some(false));
        assert_eq!(parse_ufw_status(""), None);
    }

    #[test]
    fn counts_failed_logins_from_journal_lines() {
        let journal = "\
Invalid user admin from 203.0.113.9 port 50122
Failed password for invalid user admin from 203.0.113.9 port 50122 ssh2
Failed password for root from 198.51.100.4 port 41000 ssh2
Failed publickey for ben from 2001:db8::7 port 52000 ssh2
Accepted publickey for ben from 192.0.2.10 port 50000 ssh2: ED25519 SHA256:abc
Connection closed by authenticating user root 198.51.100.4 port 41000 [preauth]
Invalid user test from 203.0.113.9 port 50200
";
        let logins = count_failed_logins("journal", journal.lines());

        assert_eq!(logins.count, 4);
        assert_eq!(logins.window_hours, 24);
        assert_eq!(
            logins.top_sources,
            vec![
                LoginSource {
                    ip: "203.0.113.9".into(),
                    count: 2
                },
                LoginSource {
                    ip: "198.51.100.4".into(),
                    count: 1
                },
                LoginSource {
                    ip: "2001:db8::7".into(),
                    count: 1
                },
            ]
        );
    }

    #[test]
    fn keeps_only_the_top_five_sources() {
        let lines: Vec<String> = (1..=7)
            .flat_map(|n| {
                std::iter::repeat(format!(
                    "Failed password for root from 192.0.2.{n} port 1 ssh2"
                ))
                .take(n)
            })
            .collect();
        let logins = count_failed_logins("journal", lines.iter().map(String::as_str));

        assert_eq!(logins.count, 28);
        assert_eq!(logins.top_sources.len(), 5);
        assert_eq!(
            logins.top_sources[0],
            LoginSource {
                ip: "192.0.2.7".into(),
                count: 7
            }
        );
    }

    #[test]
    fn reads_recent_sshd_lines_from_auth_log() {
        let text = "\
2026-10-27T09:15:02.123456+00:00 web1 sshd[812]: Failed password for root from 198.51.100.4 port 1 ssh2
2026-10-25T09:15:02.123456+00:00 web1 sshd[812]: Failed password for root from 198.51.100.5 port 1 ssh2
2026-10-27T09:16:00.000000+00:00 web1 CRON[90]: pam_unix(cron:session): session opened
2026-10-27T09:17:00.000000+00:00 web1 sshd-session[900]: Invalid user bob from 192.0.2.1 port 2
";
        let since = DateTime::parse_from_rfc3339("2026-10-26T12:00:00Z")
            .unwrap()
            .with_timezone(&Utc);
        let messages = auth_log_ssh_messages(text, since, 2026);

        assert_eq!(
            messages,
            vec![
                "Failed password for root from 198.51.100.4 port 1 ssh2",
                "Invalid user bob from 192.0.2.1 port 2"
            ]
        );
    }

    #[test]
    fn reads_classic_auth_log_stamps() {
        let text = "Oct 27 09:15:02 web1 sshd[812]: Failed password for root from 198.51.100.4 port 1 ssh2\n";
        let since = DateTime::parse_from_rfc3339("2026-10-20T00:00:00Z")
            .unwrap()
            .with_timezone(&Utc);

        assert_eq!(
            auth_log_ssh_messages(text, since, 2026),
            vec!["Failed password for root from 198.51.100.4 port 1 ssh2"]
        );
    }

    #[test]
    fn parses_ss_listening_sockets() {
        let output = r#"udp   UNCONN 0      0          127.0.0.53%lo:53         0.0.0.0:*    users:(("systemd-resolve",pid=500,fd=13))
tcp   LISTEN 0      128              0.0.0.0:22         0.0.0.0:*    users:(("sshd",pid=812,fd=3))
tcp   LISTEN 0      128                 [::]:22            [::]:*    users:(("sshd",pid=812,fd=4))
tcp   LISTEN 0      511                    *:80               *:*    users:(("nginx",pid=1001,fd=6),("nginx",pid=1000,fd=6))
tcp   LISTEN 0      511                    *:80               *:*    users:(("nginx",pid=1001,fd=6),("nginx",pid=1000,fd=6))
tcp   LISTEN 0      4096           127.0.0.1:19999      0.0.0.0:*
"#;
        let ports = parse_listening_ports(output);

        assert_eq!(ports.len(), 5);
        assert_eq!(
            ports[0],
            ListeningPort {
                protocol: "udp".into(),
                address: "127.0.0.53%lo".into(),
                port: 53,
                process_name: Some("systemd-resolve".into()),
                process_id: Some(500),
            }
        );
        assert_eq!(ports[2].address, "::");
        assert_eq!(ports[3].address, "*");
        assert_eq!(ports[3].process_name.as_deref(), Some("nginx"));
        assert_eq!(ports[4].process_name, None);
        assert_eq!(ports[4].process_id, None);
    }

    #[test]
    fn reports_once_per_interval_after_a_delivery() {
        let interval = Duration::from_secs(4 * 3600);
        let start = Instant::now();
        let mut schedule = ReportSchedule::default();

        assert!(schedule.is_due(start, interval));
        schedule.mark_sent(start);
        assert!(!schedule.is_due(start + Duration::from_secs(60), interval));
        assert!(schedule.is_due(start + interval, interval));
    }

    #[test]
    fn unknown_readings_serialise_as_null() {
        let report = LinuxSecurity {
            collected_at: "2026-10-27T09:00:00Z".into(),
            updates: Updates {
                pending: None,
                security: None,
                reboot_required: false,
                reboot_packages: vec![],
            },
            unattended_upgrades: UnattendedUpgrades {
                installed: false,
                enabled: None,
                last_run_at: None,
            },
            ssh: None,
            authorized_keys: None,
            firewall: Firewall {
                nftables_active: None,
                nftables_table_count: None,
                nftables_rule_count: None,
                ufw_active: None,
            },
            failed_logins: None,
            listening_ports: None,
        };
        let json = serde_json::to_value(&report).unwrap();

        assert!(json["updates"]["pending"].is_null());
        assert!(json["ssh"].is_null());
        assert!(json["firewall"]["nftables_active"].is_null());
        assert!(json["failed_logins"].is_null());
        assert!(json["listening_ports"].is_null());
    }
}
