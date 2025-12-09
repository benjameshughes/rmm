//! Auto-updater module for RMM Agent
//!
//! Handles checking GitHub releases for updates, downloading new versions,
//! and applying updates via in-place binary replacement.

use anyhow::{Context, Result};
use futures_util::StreamExt;
use semver::Version;
use serde::Deserialize;
use std::path::PathBuf;
use std::time::Duration;
use tokio::fs;
use tokio::io::AsyncWriteExt;
use tokio_util::sync::CancellationToken;
use tracing::{debug, error, info, warn};

use crate::config::{Config, AGENT_VERSION, GITHUB_RELEASES_URL};

/// Information about an available update
#[derive(Debug, Clone)]
pub struct UpdateInfo {
    /// Version string (e.g., "0.4.0")
    pub version: String,
    /// Download URL for the exe
    pub download_url: String,
    /// Expected file size in bytes (if available)
    pub size: Option<u64>,
}

/// Pending update marker file content
#[derive(Debug, serde::Serialize, Deserialize)]
struct PendingUpdate {
    version: String,
    exe_path: String,
    downloaded_at: String,
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

/// Auto-updater for the RMM agent
pub struct Updater {
    config: Config,
    client: reqwest::Client,
}

impl Updater {
    /// Create a new updater instance
    pub fn new(config: Config) -> Result<Self> {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(30))
            .user_agent(format!("RMM-Agent/{}", AGENT_VERSION))
            .build()
            .context("Failed to create HTTP client")?;

        Ok(Self { config, client })
    }

    /// Get the update directory path
    fn update_dir(&self) -> PathBuf {
        self.config.data_dir.join("update")
    }

    /// Get the pending update marker file path
    fn pending_marker_path(&self) -> PathBuf {
        self.update_dir().join("pending.json")
    }

    /// Get the path where new exe will be downloaded
    fn new_exe_path(&self) -> PathBuf {
        self.update_dir().join("rmm.exe.new")
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

        // Parse versions for comparison
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

        info!(
            "Current version: {}, Remote version: {}",
            current, remote
        );

        if remote <= current {
            info!("Already on latest version");
            return Ok(None);
        }

        // Find the exe asset
        let exe_asset = release
            .assets
            .iter()
            .find(|a| a.name == "rmm.exe")
            .context("No rmm.exe found in release assets")?;

        info!(
            "Update available: {} -> {} ({})",
            current, remote, exe_asset.browser_download_url
        );

        Ok(Some(UpdateInfo {
            version: remote.to_string(),
            download_url: exe_asset.browser_download_url.clone(),
            size: Some(exe_asset.size),
        }))
    }

    /// Download an update to the staging area
    pub async fn download_update(&self, info: &UpdateInfo) -> Result<PathBuf> {
        info!("Downloading update v{} from {}", info.version, info.download_url);

        // Ensure update directory exists
        let update_dir = self.update_dir();
        fs::create_dir_all(&update_dir)
            .await
            .context("Failed to create update directory")?;

        let new_exe_path = self.new_exe_path();

        // Download with streaming to handle large files
        let response = self
            .client
            .get(&info.download_url)
            .send()
            .await
            .context("Failed to start download")?;

        if !response.status().is_success() {
            anyhow::bail!("Download failed with status: {}", response.status());
        }

        let content_length = response.content_length();
        let mut file = fs::File::create(&new_exe_path)
            .await
            .context("Failed to create download file")?;

        let mut stream = response.bytes_stream();
        let mut downloaded: u64 = 0;

        while let Some(chunk) = stream.next().await {
            let chunk = chunk.context("Error reading download stream")?;
            file.write_all(&chunk)
                .await
                .context("Error writing to file")?;
            downloaded += chunk.len() as u64;

            // Log progress every 1MB
            if downloaded % (1024 * 1024) < chunk.len() as u64 {
                if let Some(total) = content_length {
                    debug!(
                        "Download progress: {:.1}MB / {:.1}MB",
                        downloaded as f64 / 1024.0 / 1024.0,
                        total as f64 / 1024.0 / 1024.0
                    );
                }
            }
        }

        file.flush().await.context("Failed to flush download")?;

        // Verify size if provided
        if let Some(expected_size) = info.size {
            let actual_size = fs::metadata(&new_exe_path)
                .await
                .context("Failed to get downloaded file size")?
                .len();

            if actual_size != expected_size {
                fs::remove_file(&new_exe_path).await.ok();
                anyhow::bail!(
                    "Downloaded file size mismatch: expected {} bytes, got {} bytes",
                    expected_size,
                    actual_size
                );
            }
        }

        info!(
            "Download complete: {} ({} bytes)",
            new_exe_path.display(),
            downloaded
        );

        // Write pending marker
        let pending = PendingUpdate {
            version: info.version.clone(),
            exe_path: new_exe_path.to_string_lossy().to_string(),
            downloaded_at: chrono::Utc::now().to_rfc3339(),
        };

        let marker_path = self.pending_marker_path();
        let marker_json =
            serde_json::to_string_pretty(&pending).context("Failed to serialize pending marker")?;
        fs::write(&marker_path, marker_json)
            .await
            .context("Failed to write pending marker")?;

        info!("Pending update marker written to {}", marker_path.display());

        Ok(new_exe_path)
    }

    /// Trigger service restart (Windows SCM will auto-restart the service)
    #[cfg(target_os = "windows")]
    pub fn trigger_restart(&self) -> Result<()> {
        info!("Triggering service restart for update application");

        // Use sc.exe to stop the service - SCM will restart it automatically
        let output = std::process::Command::new("sc.exe")
            .args(["stop", "BenJHRMM"])
            .output()
            .context("Failed to execute sc.exe")?;

        if !output.status.success() {
            let stderr = String::from_utf8_lossy(&output.stderr);
            // Service might already be stopping or not running
            if !stderr.contains("has not been started") && !stderr.contains("STOP_PENDING") {
                warn!("sc stop returned: {}", stderr);
            }
        }

        info!("Service stop requested - SCM will restart automatically");
        Ok(())
    }

    #[cfg(not(target_os = "windows"))]
    pub fn trigger_restart(&self) -> Result<()> {
        info!("Non-Windows platform: manual restart required");
        Ok(())
    }

    /// Apply a pending update (called at startup BEFORE service registration)
    ///
    /// Returns true if an update was applied
    pub fn apply_pending_update(config: &Config) -> Result<bool> {
        let update_dir = config.data_dir.join("update");
        let marker_path = update_dir.join("pending.json");

        if !marker_path.exists() {
            debug!("No pending update marker found");
            return Ok(false);
        }

        info!("Found pending update marker at {}", marker_path.display());

        // Read the marker
        let marker_content =
            std::fs::read_to_string(&marker_path).context("Failed to read pending marker")?;
        let pending: PendingUpdate =
            serde_json::from_str(&marker_content).context("Failed to parse pending marker")?;

        info!("Applying pending update to v{}", pending.version);

        let new_exe_path = PathBuf::from(&pending.exe_path);
        if !new_exe_path.exists() {
            warn!("Pending update exe not found, cleaning up marker");
            std::fs::remove_file(&marker_path).ok();
            return Ok(false);
        }

        // Get current exe path
        let current_exe =
            std::env::current_exe().context("Failed to get current executable path")?;
        let backup_exe = current_exe.with_extension("exe.bak");

        info!("Current exe: {}", current_exe.display());
        info!("New exe: {}", new_exe_path.display());
        info!("Backup exe: {}", backup_exe.display());

        // Perform the swap
        // 1. Remove old backup if exists
        if backup_exe.exists() {
            std::fs::remove_file(&backup_exe).ok();
        }

        // 2. Rename current -> backup
        if let Err(e) = std::fs::rename(&current_exe, &backup_exe) {
            error!("Failed to backup current exe: {}", e);
            // Clean up and continue running current version
            std::fs::remove_file(&marker_path).ok();
            std::fs::remove_file(&new_exe_path).ok();
            return Ok(false);
        }

        // 3. Move new exe -> current location
        if let Err(e) = std::fs::rename(&new_exe_path, &current_exe) {
            error!("Failed to move new exe: {}", e);
            // Rollback: restore backup
            if let Err(rollback_err) = std::fs::rename(&backup_exe, &current_exe) {
                error!("CRITICAL: Rollback failed: {}", rollback_err);
            }
            std::fs::remove_file(&marker_path).ok();
            return Ok(false);
        }

        // 4. Clean up marker
        std::fs::remove_file(&marker_path).ok();

        // 5. Clean up old backup after a successful update (keep it for now for manual rollback)
        // std::fs::remove_file(&backup_exe).ok();

        info!(
            "Update applied successfully! Now running v{}",
            pending.version
        );
        Ok(true)
    }

    /// Start the update check loop
    pub async fn start_update_loop(&self, cancellation_token: CancellationToken) {
        if self.config.skip_updates {
            info!("Automatic updates are disabled");
            return;
        }

        info!(
            "Starting update check loop (interval: {}s)",
            self.config.update_check_interval
        );

        // Check immediately on startup
        if let Err(e) = self.check_and_download().await {
            warn!("Initial update check failed: {}", e);
        }

        loop {
            tokio::select! {
                _ = cancellation_token.cancelled() => {
                    info!("Update loop cancelled - shutting down");
                    break;
                }
                _ = tokio::time::sleep(Duration::from_secs(self.config.update_check_interval)) => {
                    if let Err(e) = self.check_and_download().await {
                        warn!("Scheduled update check failed: {}", e);
                    }
                }
            }
        }
    }

    /// Check for update and download if available
    async fn check_and_download(&self) -> Result<()> {
        match self.check_for_update().await {
            Ok(Some(info)) => {
                info!("Update available: v{}", info.version);

                // Download the update
                match self.download_update(&info).await {
                    Ok(_) => {
                        info!("Update downloaded, triggering restart to apply");
                        self.trigger_restart()?;
                    }
                    Err(e) => {
                        error!("Failed to download update: {}", e);
                    }
                }
            }
            Ok(None) => {
                debug!("No update available");
            }
            Err(e) => {
                warn!("Update check error: {}", e);
            }
        }
        Ok(())
    }

    /// Manual update check (for CLI command)
    pub async fn check_only(&self) -> Result<Option<UpdateInfo>> {
        self.check_for_update().await
    }

    /// Manual update (for CLI command)
    pub async fn update_now(&self) -> Result<bool> {
        match self.check_for_update().await? {
            Some(info) => {
                self.download_update(&info).await?;
                info!("Update downloaded. Restart the service to apply.");
                Ok(true)
            }
            None => {
                info!("Already on the latest version ({})", AGENT_VERSION);
                Ok(false)
            }
        }
    }
}
