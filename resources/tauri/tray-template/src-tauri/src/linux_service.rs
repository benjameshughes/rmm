//! systemd service for the Linux (monitor-only) agent.
//!
//! `rmm install` writes `/etc/systemd/system/benjh-rmm.service` (runs
//! `rmm run`, restarts always), reloads systemd and enables + starts it;
//! `rmm uninstall` stops, disables and removes it. The data directory
//! (`/var/lib/benjh-rmm`, root only) is left in place so a reinstall keeps
//! its enrollment.

// The unit text is tested everywhere; only Linux builds install it.
#![cfg_attr(not(target_os = "linux"), allow(dead_code))]

use std::path::Path;

/// systemd unit name (without `.service`).
pub const UNIT_NAME: &str = "benjh-rmm";

/// Where the unit file is written.
pub const UNIT_PATH: &str = "/etc/systemd/system/benjh-rmm.service";

/// Quote a path for an ExecStart= line: wrapped in double quotes, with
/// backslashes, quotes and systemd's `%` specifier character escaped.
pub fn systemd_quote(path: &Path) -> String {
    let escaped = path
        .to_string_lossy()
        .replace('\\', "\\\\")
        .replace('"', "\\\"")
        .replace('%', "%%");
    format!("\"{escaped}\"")
}

/// The unit file for an agent installed at `exe`.
pub fn systemd_unit(exe: &Path) -> String {
    format!(
        "[Unit]\n\
Description=BenJH RMM monitoring agent (read-only)\n\
Wants=network-online.target\n\
After=network-online.target\n\
\n\
[Service]\n\
Type=simple\n\
ExecStart={} run\n\
Restart=always\n\
RestartSec=10\n\
NoNewPrivileges=true\n\
\n\
[Install]\n\
WantedBy=multi-user.target\n",
        systemd_quote(exe)
    )
}

/// `systemctl` argument lists, in order, for each service action.
pub fn install_steps() -> Vec<Vec<&'static str>> {
    vec![vec!["daemon-reload"], vec!["enable", "--now", UNIT_NAME]]
}

pub fn uninstall_steps() -> Vec<Vec<&'static str>> {
    vec![vec!["disable", "--now", UNIT_NAME]]
}

/// Restart after a self-update. `--no-block` queues the job and returns at
/// once, so stopping this service (and its cgroup) cannot cancel it.
pub fn restart_args() -> Vec<&'static str> {
    vec!["--no-block", "restart", UNIT_NAME]
}

#[cfg(target_os = "linux")]
mod linux {
    use super::*;
    use anyhow::{Context, Result};
    use std::process::Command;

    fn systemctl(args: &[&str]) -> Result<()> {
        let status = Command::new("systemctl")
            .args(args)
            .status()
            .with_context(|| format!("Failed to run systemctl {}", args.join(" ")))?;

        if !status.success() {
            anyhow::bail!("systemctl {} failed ({})", args.join(" "), status);
        }
        Ok(())
    }

    pub fn install() -> Result<()> {
        let exe = std::env::current_exe()
            .and_then(|path| path.canonicalize())
            .context("Cannot find the agent executable")?;

        std::fs::write(UNIT_PATH, systemd_unit(&exe))
            .with_context(|| format!("Cannot write {} (run with sudo)", UNIT_PATH))?;

        for step in install_steps() {
            systemctl(&step)?;
        }

        println!("Service '{}' installed and started", UNIT_NAME);
        println!("Logs: rmm logs  (or journalctl -u {})", UNIT_NAME);
        Ok(())
    }

    pub fn uninstall() -> Result<()> {
        for step in uninstall_steps() {
            if let Err(e) = systemctl(&step) {
                eprintln!("Warning: {:#}", e);
            }
        }

        match std::fs::remove_file(UNIT_PATH) {
            Ok(()) => {}
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
            Err(e) => {
                return Err(e)
                    .with_context(|| format!("Cannot remove {} (run with sudo)", UNIT_PATH))
            }
        }
        systemctl(&["daemon-reload"])?;

        println!(
            "Service '{}' removed (data in /var/lib/benjh-rmm kept)",
            UNIT_NAME
        );
        Ok(())
    }

    pub fn start() -> Result<()> {
        systemctl(&["start", UNIT_NAME])?;
        println!("Service '{}' started", UNIT_NAME);
        Ok(())
    }

    pub fn stop() -> Result<()> {
        systemctl(&["stop", UNIT_NAME])?;
        println!("Service '{}' stopped", UNIT_NAME);
        Ok(())
    }

    /// Detached restart after a self-update.
    pub fn restart_detached() -> Result<()> {
        Command::new("systemctl")
            .args(restart_args())
            .spawn()
            .context("Failed to spawn systemctl restart")?;
        Ok(())
    }
}

#[cfg(target_os = "linux")]
pub use linux::{install, restart_detached, start, stop, uninstall};

#[cfg(test)]
mod tests {
    use super::*;
    use std::path::PathBuf;

    #[test]
    fn writes_a_unit_that_runs_the_agent_and_always_restarts() {
        let unit = systemd_unit(Path::new("/usr/local/bin/rmm"));

        assert!(unit.starts_with("[Unit]\n"));
        assert!(unit.contains("\nExecStart=\"/usr/local/bin/rmm\" run\n"));
        assert!(unit.contains("\nRestart=always\n"));
        assert!(unit.contains("\nAfter=network-online.target\n"));
        assert!(unit.contains("\n[Install]\nWantedBy=multi-user.target\n"));
        assert!(unit.contains("read-only"));
    }

    #[test]
    fn quotes_awkward_paths() {
        assert_eq!(
            systemd_quote(&PathBuf::from("/opt/my rmm/100%/r\"mm")),
            "\"/opt/my rmm/100%%/r\\\"mm\""
        );
    }

    #[test]
    fn installs_by_reloading_then_enabling_now() {
        assert_eq!(
            install_steps(),
            vec![vec!["daemon-reload"], vec!["enable", "--now", "benjh-rmm"]]
        );
        assert_eq!(
            uninstall_steps(),
            vec![vec!["disable", "--now", "benjh-rmm"]]
        );
        assert_eq!(UNIT_PATH, "/etc/systemd/system/benjh-rmm.service");
    }

    #[test]
    fn restarts_without_blocking() {
        assert_eq!(restart_args(), vec!["--no-block", "restart", "benjh-rmm"]);
    }
}
