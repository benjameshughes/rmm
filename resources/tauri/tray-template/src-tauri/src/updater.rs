//! Auto-updater module for RMM Agent
//!
//! Checks GitHub releases for a newer version, downloads `rmm.exe` together
//! with its published `rmm.exe.sha256`, verifies the SHA-256 in memory and only
//! then replaces the installed executable and restarts the service.
//!
//! Nothing is staged on disk between download and install, so there is no
//! marker file or staged binary that another local user could plant or swap.
//! (Older versions staged `update\rmm.exe.new` + `update\pending.json` and
//! trusted the path in the marker; the service now deletes the whole
//! `update\` directory at startup and never reads it.)

use anyhow::{Context, Result};
use futures_util::StreamExt;
use semver::Version;
use serde::Deserialize;
use sha2::{Digest, Sha256};
use std::io::Write;
use std::path::{Path, PathBuf};
use std::time::Duration;
use tokio_util::sync::CancellationToken;
use tracing::{debug, error, info, warn};

use crate::config::{Config, AGENT_VERSION, GITHUB_RELEASES_URL};

/// Name of the executable asset in the GitHub release.
pub const EXE_ASSET_NAME: &str = "rmm.exe";
/// Name of the checksum asset (lowercase hex SHA-256 of rmm.exe).
pub const CHECKSUM_ASSET_NAME: &str = "rmm.exe.sha256";
/// Refuse downloads larger than this (the agent is a few MB).
const MAX_EXE_BYTES: u64 = 200 * 1024 * 1024;
/// Refuse checksum files larger than this.
const MAX_CHECKSUM_BYTES: usize = 4096;

/// Information about an available update
#[derive(Debug, Clone)]
pub struct UpdateInfo {
    /// Version string (e.g., "0.4.0")
    pub version: String,
    /// Download URL for the exe
    pub download_url: String,
    /// Download URL for the SHA-256 checksum file
    pub checksum_url: String,
    /// Expected file size in bytes (if available)
    pub size: Option<u64>,
}

/// GitHub release API response
#[derive(Debug, Deserialize)]
struct GitHubRelease {
    tag_name: String,
    assets: Vec<GitHubAsset>,
}

/// GitHub asset in a release
#[derive(Debug, Deserialize)]
struct GitHubAsset {
    name: String,
    browser_download_url: String,
    size: u64,
}

/// Parse the contents of a checksum file. Accepts a bare hash or
/// `sha256sum`-style `<hash>  <filename>`, any case, optional BOM/whitespace.
/// Returns the lowercase hex digest.
pub fn parse_checksum(text: &str) -> Result<String> {
    let token = text
        .trim_start_matches('\u{feff}')
        .split_whitespace()
        .next()
        .context("Checksum file is empty")?;

    if token.len() != 64 || !token.chars().all(|c| c.is_ascii_hexdigit()) {
        anyhow::bail!("Checksum file does not contain a SHA-256 hex digest");
    }

    Ok(token.to_ascii_lowercase())
}

/// Lowercase hex SHA-256 of `bytes`.
pub fn sha256_hex(bytes: &[u8]) -> String {
    hex::encode(Sha256::digest(bytes))
}

/// Verify `bytes` against an expected lowercase hex SHA-256.
pub fn verify_sha256(bytes: &[u8], expected_hex: &str) -> Result<()> {
    let actual = sha256_hex(bytes);
    if actual != expected_hex.to_ascii_lowercase() {
        anyhow::bail!(
            "SHA-256 mismatch: expected {}, got {}",
            expected_hex,
            actual
        );
    }
    Ok(())
}

/// Path used to keep the previous executable after an update.
pub fn backup_path(target: &Path) -> PathBuf {
    let name = target
        .file_name()
        .map(|n| n.to_string_lossy().to_string())
        .unwrap_or_else(|| EXE_ASSET_NAME.to_string());
    target.with_file_name(format!("{}.bak", name))
}

/// Replace the executable at `target` with `bytes`, which must match
/// `expected_sha256`. The current executable is renamed to `<name>.bak`
/// (renaming a running executable is allowed on Windows) and the new one is
/// written as a fresh file, so it gets the install directory's normal ACL.
/// On any failure the original executable is restored.
pub fn install_executable(target: &Path, bytes: &[u8], expected_sha256: &str) -> Result<()> {
    verify_sha256(bytes, expected_sha256).context("Refusing to install unverified executable")?;

    let backup = backup_path(target);
    let original_permissions = std::fs::metadata(target)
        .with_context(|| format!("Cannot stat current executable {}", target.display()))?
        .permissions();

    // A previous backup may still be the image of a running process (if the
    // service was not restarted after the last update); then the rename below
    // fails and we abort without touching anything.
    if backup.exists() {
        if let Err(e) = std::fs::remove_file(&backup) {
            warn!("Could not remove old backup {}: {}", backup.display(), e);
        }
    }

    std::fs::rename(target, &backup).with_context(|| {
        format!(
            "Failed to move current executable {} to {}",
            target.display(),
            backup.display()
        )
    })?;

    let write_result = (|| -> Result<()> {
        let mut file = std::fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .open(target)
            .context("Failed to create new executable")?;
        file.write_all(bytes).context("Failed to write new executable")?;
        file.sync_all().context("Failed to flush new executable")?;
        drop(file);

        std::fs::set_permissions(target, original_permissions.clone())
            .context("Failed to set permissions on new executable")?;

        let written = std::fs::read(target).context("Failed to read back new executable")?;
        verify_sha256(&written, expected_sha256).context("Written executable failed verification")?;
        Ok(())
    })();

    if let Err(e) = write_result {
        error!("Update install failed, rolling back: {:#}", e);
        let _ = std::fs::remove_file(target);
        if let Err(rollback_err) = std::fs::rename(&backup, target) {
            error!(
                "CRITICAL: rollback failed, previous executable left at {}: {}",
                backup.display(),
                rollback_err
            );
        }
        return Err(e);
    }

    Ok(())
}

/// Auto-updates are only published for Windows (rmm.exe).
pub const fn auto_update_supported() -> bool {
    cfg!(target_os = "windows")
}

/// Auto-updater for the RMM agent
pub struct Updater {
    config: Config,
    client: reqwest::Client,
}

impl Updater {
    /// Create a new updater instance
    pub fn new(config: Config) -> Result<Self> {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(120))
            .user_agent(format!("RMM-Agent/{}", AGENT_VERSION))
            .build()
            .context("Failed to create HTTP client")?;

        Ok(Self { config, client })
    }

    /// Check GitHub for a newer version
    pub async fn check_for_update(&self) -> Result<Option<UpdateInfo>> {
        info!("Checking for updates at {}", GITHUB_RELEASES_URL);

        let response = self
            .client
            .get(GITHUB_RELEASES_URL)
            .header("Accept", "application/vnd.github.v3+json")
            .send()
            .await
            .context("Failed to fetch GitHub releases")?;

        if !response.status().is_success() {
            let status = response.status();
            let body = response.text().await.unwrap_or_default();
            anyhow::bail!("GitHub API returned {}: {}", status, body);
        }

        let release: GitHubRelease = response
            .json()
            .await
            .context("Failed to parse GitHub release JSON")?;

        // Parse the tag name (e.g., "v0.4.0" -> "0.4.0")
        let remote_version_str = release.tag_name.trim_start_matches('v');

        let current = Version::parse(AGENT_VERSION).context("Invalid current version")?;
        let remote = match Version::parse(remote_version_str) {
            Ok(v) => v,
            Err(e) => {
                warn!(
                    "Could not parse remote version '{}': {}",
                    remote_version_str, e
                );
                return Ok(None);
            }
        };

        info!("Current version: {}, Remote version: {}", current, remote);

        if remote <= current {
            info!("Already on latest version");
            return Ok(None);
        }

        let exe_asset = release
            .assets
            .iter()
            .find(|a| a.name == EXE_ASSET_NAME)
            .context("No rmm.exe found in release assets")?;

        let checksum_asset = release
            .assets
            .iter()
            .find(|a| a.name == CHECKSUM_ASSET_NAME)
            .with_context(|| {
                format!(
                    "Release v{} has no {} asset - refusing to update",
                    remote, CHECKSUM_ASSET_NAME
                )
            })?;

        info!(
            "Update available: {} -> {} ({})",
            current, remote, exe_asset.browser_download_url
        );

        Ok(Some(UpdateInfo {
            version: remote.to_string(),
            download_url: exe_asset.browser_download_url.clone(),
            checksum_url: checksum_asset.browser_download_url.clone(),
            size: Some(exe_asset.size),
        }))
    }

    /// Download the expected checksum for an update.
    async fn fetch_expected_checksum(&self, info: &UpdateInfo) -> Result<String> {
        let response = self
            .client
            .get(&info.checksum_url)
            .send()
            .await
            .context("Failed to download checksum")?;

        if !response.status().is_success() {
            anyhow::bail!("Checksum download failed with status: {}", response.status());
        }

        let bytes = response.bytes().await.context("Failed to read checksum")?;
        if bytes.len() > MAX_CHECKSUM_BYTES {
            anyhow::bail!("Checksum file is unexpectedly large ({} bytes)", bytes.len());
        }

        parse_checksum(&String::from_utf8_lossy(&bytes))
    }

    /// Download an update into memory and verify its SHA-256.
    /// Returns the verified bytes and the hex digest.
    pub async fn download_verified(&self, info: &UpdateInfo) -> Result<(Vec<u8>, String)> {
        info!("Downloading update v{} from {}", info.version, info.download_url);

        let expected = self.fetch_expected_checksum(info).await?;

        let response = self
            .client
            .get(&info.download_url)
            .send()
            .await
            .context("Failed to start download")?;

        if !response.status().is_success() {
            anyhow::bail!("Download failed with status: {}", response.status());
        }

        if let Some(len) = response.content_length() {
            if len > MAX_EXE_BYTES {
                anyhow::bail!("Download is unexpectedly large ({} bytes)", len);
            }
        }

        let mut bytes: Vec<u8> = Vec::new();
        let mut stream = response.bytes_stream();
        while let Some(chunk) = stream.next().await {
            let chunk = chunk.context("Error reading download stream")?;
            if bytes.len() as u64 + chunk.len() as u64 > MAX_EXE_BYTES {
                anyhow::bail!("Download exceeded maximum size");
            }
            bytes.extend_from_slice(&chunk);
        }

        if let Some(expected_size) = info.size {
            if bytes.len() as u64 != expected_size {
                anyhow::bail!(
                    "Downloaded file size mismatch: expected {} bytes, got {} bytes",
                    expected_size,
                    bytes.len()
                );
            }
        }

        verify_sha256(&bytes, &expected).context("Downloaded update failed verification")?;
        info!(
            "Downloaded and verified update v{} ({} bytes, sha256 {})",
            info.version,
            bytes.len(),
            expected
        );

        Ok((bytes, expected))
    }

    /// Download, verify and install an update over the running executable.
    pub async fn download_and_install(&self, info: &UpdateInfo) -> Result<PathBuf> {
        if !auto_update_supported() {
            anyhow::bail!("Automatic updates are only supported on Windows");
        }

        let (bytes, sha256) = self.download_verified(info).await?;
        let current_exe =
            std::env::current_exe().context("Failed to get current executable path")?;

        install_executable(&current_exe, &bytes, &sha256)?;
        info!(
            "Installed v{} to {} (previous version kept as {})",
            info.version,
            current_exe.display(),
            backup_path(&current_exe).display()
        );
        Ok(current_exe)
    }

    /// Restart the service so the new executable is loaded.
    ///
    /// A service cannot start itself after it has stopped, so a detached
    /// PowerShell process performs Restart-Service (stop, wait, start).
    #[cfg(target_os = "windows")]
    pub fn trigger_restart(&self) -> Result<()> {
        info!("Restarting service to load the new version");

        let system_root = std::env::var("SystemRoot").ok();
        let powershell = crate::data_dir_security::system32_tool(
            system_root.as_deref(),
            r"WindowsPowerShell\v1.0\powershell.exe",
        );

        std::process::Command::new(powershell)
            .args([
                "-NoProfile",
                "-NonInteractive",
                "-ExecutionPolicy",
                "Bypass",
                "-Command",
                "Start-Sleep -Seconds 2; Restart-Service -Name 'BenJHRMM' -Force",
            ])
            .spawn()
            .context("Failed to spawn service restart")?;

        Ok(())
    }

    #[cfg(not(target_os = "windows"))]
    pub fn trigger_restart(&self) -> Result<()> {
        info!("Non-Windows platform: manual restart required");
        Ok(())
    }

    /// Start the update check loop
    pub async fn start_update_loop(&self, cancellation_token: CancellationToken) {
        if self.config.skip_updates {
            info!("Automatic updates are disabled");
            return;
        }

        if !auto_update_supported() {
            info!("Automatic updates are only supported on Windows - update loop disabled");
            return;
        }

        info!(
            "Starting update check loop (interval: {}s)",
            self.config.update_check_interval
        );

        // Check immediately on startup
        if self.check_and_install().await {
            return;
        }

        loop {
            tokio::select! {
                _ = cancellation_token.cancelled() => {
                    info!("Update loop cancelled - shutting down");
                    break;
                }
                _ = tokio::time::sleep(Duration::from_secs(self.config.update_check_interval)) => {
                    if self.check_and_install().await {
                        break;
                    }
                }
            }
        }
    }

    /// Check for an update and install it if available.
    /// Returns true if an update was installed (a restart is pending).
    async fn check_and_install(&self) -> bool {
        match self.check_for_update().await {
            Ok(Some(info)) => match self.download_and_install(&info).await {
                Ok(_) => {
                    if let Err(e) = self.trigger_restart() {
                        error!(
                            "Update v{} installed but restart failed: {} - it will load on next service start",
                            info.version, e
                        );
                    }
                    true
                }
                Err(e) => {
                    error!("Failed to install update v{}: {:#}", info.version, e);
                    false
                }
            },
            Ok(None) => {
                debug!("No update available");
                false
            }
            Err(e) => {
                warn!("Update check error: {:#}", e);
                false
            }
        }
    }

    /// Manual update check (for CLI command)
    pub async fn check_only(&self) -> Result<Option<UpdateInfo>> {
        self.check_for_update().await
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tempfile::TempDir;

    const HELLO_SHA: &str = "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824";

    #[test]
    fn parses_bare_and_sha256sum_formats() {
        assert_eq!(parse_checksum(HELLO_SHA).unwrap(), HELLO_SHA);
        assert_eq!(
            parse_checksum(&format!("{}  rmm.exe\n", HELLO_SHA.to_uppercase())).unwrap(),
            HELLO_SHA
        );
        assert_eq!(
            parse_checksum(&format!("\u{feff}{}\r\n", HELLO_SHA)).unwrap(),
            HELLO_SHA
        );
    }

    #[test]
    fn rejects_bad_checksums() {
        assert!(parse_checksum("").is_err());
        assert!(parse_checksum("   \n").is_err());
        assert!(parse_checksum("abc123").is_err());
        assert!(parse_checksum(&"z".repeat(64)).is_err());
        assert!(parse_checksum("<html>Not Found</html>").is_err());
    }

    #[test]
    fn verifies_sha256() {
        assert_eq!(sha256_hex(b"hello"), HELLO_SHA);
        assert!(verify_sha256(b"hello", HELLO_SHA).is_ok());
        assert!(verify_sha256(b"hello", &HELLO_SHA.to_uppercase()).is_ok());
        assert!(verify_sha256(b"hellO", HELLO_SHA).is_err());
    }

    #[test]
    fn backup_path_appends_bak() {
        assert_eq!(
            backup_path(Path::new("/opt/rmm/rmm.exe")),
            PathBuf::from("/opt/rmm/rmm.exe.bak")
        );
        assert_eq!(
            backup_path(Path::new("/opt/rmm/rmm")),
            PathBuf::from("/opt/rmm/rmm.bak")
        );
    }

    #[test]
    fn installs_verified_executable_and_keeps_backup() {
        let tmp = TempDir::new().unwrap();
        let target = tmp.path().join("rmm.exe");
        std::fs::write(&target, b"old").unwrap();

        install_executable(&target, b"hello", HELLO_SHA).unwrap();

        assert_eq!(std::fs::read(&target).unwrap(), b"hello");
        assert_eq!(std::fs::read(backup_path(&target)).unwrap(), b"old");
    }

    #[test]
    fn replaces_stale_backup() {
        let tmp = TempDir::new().unwrap();
        let target = tmp.path().join("rmm.exe");
        std::fs::write(&target, b"old").unwrap();
        std::fs::write(backup_path(&target), b"older").unwrap();

        install_executable(&target, b"hello", HELLO_SHA).unwrap();

        assert_eq!(std::fs::read(&target).unwrap(), b"hello");
        assert_eq!(std::fs::read(backup_path(&target)).unwrap(), b"old");
    }

    #[test]
    fn refuses_unverified_executable_without_touching_disk() {
        let tmp = TempDir::new().unwrap();
        let target = tmp.path().join("rmm.exe");
        std::fs::write(&target, b"old").unwrap();

        assert!(install_executable(&target, b"evil", HELLO_SHA).is_err());

        assert_eq!(std::fs::read(&target).unwrap(), b"old");
        assert!(!backup_path(&target).exists());
    }

    #[cfg(unix)]
    #[test]
    fn preserves_executable_permissions() {
        use std::os::unix::fs::PermissionsExt;

        let tmp = TempDir::new().unwrap();
        let target = tmp.path().join("rmm");
        std::fs::write(&target, b"old").unwrap();
        std::fs::set_permissions(&target, std::fs::Permissions::from_mode(0o755)).unwrap();

        install_executable(&target, b"hello", HELLO_SHA).unwrap();

        let mode = std::fs::metadata(&target).unwrap().permissions().mode();
        assert_eq!(mode & 0o777, 0o755);
    }

    #[test]
    fn fails_cleanly_when_target_missing() {
        let tmp = TempDir::new().unwrap();
        let target = tmp.path().join("missing-dir").join("rmm.exe");
        assert!(install_executable(&target, b"hello", HELLO_SHA).is_err());
        assert!(!backup_path(&target).exists());
    }
}
