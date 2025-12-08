use anyhow::{Context, Result};
use futures_util::StreamExt;
use serde::Deserialize;
use std::path::PathBuf;
use std::time::Duration;
use tracing::{debug, info, warn};

const GITHUB_RELEASES_URL: &str = "https://api.github.com/repos/benjameshughes/rmm-agent/releases/latest";
const CURRENT_VERSION: &str = env!("CARGO_PKG_VERSION");

#[derive(Debug, Deserialize)]
struct GitHubRelease {
    tag_name: String,
    assets: Vec<GitHubAsset>,
}

#[derive(Debug, Deserialize)]
struct GitHubAsset {
    name: String,
    browser_download_url: String,
}

pub struct Updater {
    client: reqwest::Client,
}

impl Updater {
    pub fn new() -> Result<Self> {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(30))
            .user_agent("benjh-rmm-agent")
            .build()
            .context("Failed to create HTTP client")?;

        Ok(Self { client })
    }

    /// Check if a newer version is available
    pub async fn check_for_update(&self) -> Result<Option<UpdateInfo>> {
        debug!("Checking for updates at {}", GITHUB_RELEASES_URL);

        let response = self
            .client
            .get(GITHUB_RELEASES_URL)
            .send()
            .await
            .context("Failed to fetch latest release")?;

        if !response.status().is_success() {
            warn!("GitHub API returned status: {}", response.status());
            return Ok(None);
        }

        let release: GitHubRelease = response
            .json()
            .await
            .context("Failed to parse release response")?;

        let latest_version = release.tag_name.trim_start_matches('v');

        debug!("Current version: {}, Latest version: {}", CURRENT_VERSION, latest_version);

        if is_newer_version(latest_version, CURRENT_VERSION) {
            // Find the Windows exe asset
            let download_url = release
                .assets
                .iter()
                .find(|a| a.name.ends_with(".exe"))
                .map(|a| a.browser_download_url.clone());

            if let Some(url) = download_url {
                info!("Update available: {} -> {}", CURRENT_VERSION, latest_version);
                return Ok(Some(UpdateInfo {
                    current_version: CURRENT_VERSION.to_string(),
                    latest_version: latest_version.to_string(),
                    download_url: url,
                }));
            }
        }

        debug!("No update available");
        Ok(None)
    }

    /// Download update with streaming progress
    pub async fn download_update(&self, update: &UpdateInfo) -> Result<PathBuf> {
        info!("Downloading update from {}", update.download_url);

        let response = self
            .client
            .get(&update.download_url)
            .send()
            .await
            .context("Failed to download update")?;

        if !response.status().is_success() {
            anyhow::bail!("Download failed with status: {}", response.status());
        }

        // Create updates directory
        #[cfg(target_os = "windows")]
        let updates_dir = PathBuf::from(r"C:\ProgramData\RMM\updates");

        #[cfg(target_os = "macos")]
        let updates_dir = dirs::data_dir()
            .map(|p| p.join("RMM").join("updates"))
            .unwrap_or_else(|| PathBuf::from("/tmp/RMM/updates"));

        #[cfg(target_os = "linux")]
        let updates_dir = PathBuf::from("/var/lib/rmm/updates");

        tokio::fs::create_dir_all(&updates_dir)
            .await
            .context("Failed to create updates directory")?;

        // Determine file extension based on platform
        #[cfg(target_os = "windows")]
        let file_name = format!("update-{}.exe", update.latest_version);

        #[cfg(target_os = "macos")]
        let file_name = format!("update-{}.app.zip", update.latest_version);

        #[cfg(target_os = "linux")]
        let file_name = format!("update-{}", update.latest_version);

        let download_path = updates_dir.join(file_name);

        // Stream download to file
        let mut file = tokio::fs::File::create(&download_path)
            .await
            .context("Failed to create download file")?;

        let mut stream = response.bytes_stream();
        while let Some(chunk) = stream.next().await {
            let chunk = chunk.context("Failed to read chunk")?;
            tokio::io::AsyncWriteExt::write_all(&mut file, &chunk)
                .await
                .context("Failed to write chunk")?;
        }

        info!("Update downloaded to {:?}", download_path);
        Ok(download_path)
    }

    /// Download and apply update (legacy method)
    pub async fn download_and_apply(&self, update: &UpdateInfo) -> Result<()> {
        let downloaded_path = self.download_update(update).await?;
        Self::apply_update(&downloaded_path)?;
        Ok(())
    }

    /// Apply update based on platform
    #[cfg(target_os = "windows")]
    pub fn apply_update_windows(downloaded_path: &std::path::Path) -> Result<()> {
        info!("Preparing Windows update from {:?}", downloaded_path);

        let current_exe = std::env::current_exe().context("Failed to get current exe path")?;
        let update_script = current_exe
            .parent()
            .unwrap_or(&PathBuf::from("."))
            .join("update.bat");

        // Create batch script to replace exe after exit
        let script_content = format!(
            r#"@echo off
timeout /t 2 /nobreak > NUL
copy /y "{}" "{}"
del "%~f0"
"#,
            downloaded_path.display(),
            current_exe.display()
        );

        std::fs::write(&update_script, script_content)
            .context("Failed to write update script")?;

        info!("Update script created at {:?}", update_script);
        info!("Please restart the application to complete the update");

        // Execute the batch script in a detached process
        std::process::Command::new("cmd")
            .args(&["/C", "start", "", update_script.to_str().unwrap()])
            .spawn()
            .context("Failed to start update script")?;

        Ok(())
    }

    /// Apply update based on platform
    #[cfg(target_os = "macos")]
    pub fn apply_update_macos(downloaded_path: &std::path::Path) -> Result<()> {
        info!("Preparing macOS update from {:?}", downloaded_path);

        let current_exe = std::env::current_exe().context("Failed to get current exe path")?;
        let update_script = PathBuf::from("/tmp/rmm-update.sh");

        // Create shell script to replace app after exit
        let script_content = format!(
            r#"#!/bin/bash
sleep 2
unzip -q "{}" -d /tmp/rmm-update
rm -rf "$(dirname "{}")"
mv /tmp/rmm-update/*.app "$(dirname "{}")"
rm -rf /tmp/rmm-update
rm -- "$0"
"#,
            downloaded_path.display(),
            current_exe.display(),
            current_exe.display()
        );

        std::fs::write(&update_script, script_content)
            .context("Failed to write update script")?;

        // Make script executable
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            let mut perms = std::fs::metadata(&update_script)?.permissions();
            perms.set_mode(0o755);
            std::fs::set_permissions(&update_script, perms)?;
        }

        info!("Update script created at {:?}", update_script);
        info!("Please restart the application to complete the update");

        // Execute the shell script in a detached process
        std::process::Command::new("sh")
            .arg(update_script)
            .spawn()
            .context("Failed to start update script")?;

        Ok(())
    }

    /// Apply update (platform-agnostic wrapper)
    pub fn apply_update(downloaded_path: &std::path::Path) -> Result<()> {
        #[cfg(target_os = "windows")]
        return Self::apply_update_windows(downloaded_path);

        #[cfg(target_os = "macos")]
        return Self::apply_update_macos(downloaded_path);

        #[cfg(not(any(target_os = "windows", target_os = "macos")))]
        anyhow::bail!("Update apply not implemented for this platform");
    }

    pub fn current_version() -> &'static str {
        CURRENT_VERSION
    }
}

#[derive(Debug, Clone, serde::Serialize)]
pub struct UpdateInfo {
    pub current_version: String,
    pub latest_version: String,
    pub download_url: String,
}

/// Compare semantic versions (simple implementation)
fn is_newer_version(latest: &str, current: &str) -> bool {
    let parse = |v: &str| -> Vec<u32> {
        v.split('.')
            .filter_map(|s| s.parse().ok())
            .collect()
    };

    let latest_parts = parse(latest);
    let current_parts = parse(current);

    for i in 0..3 {
        let l = latest_parts.get(i).unwrap_or(&0);
        let c = current_parts.get(i).unwrap_or(&0);
        if l > c {
            return true;
        }
        if l < c {
            return false;
        }
    }
    false
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_version_comparison() {
        assert!(is_newer_version("1.0.1", "1.0.0"));
        assert!(is_newer_version("1.1.0", "1.0.0"));
        assert!(is_newer_version("2.0.0", "1.9.9"));
        assert!(!is_newer_version("1.0.0", "1.0.0"));
        assert!(!is_newer_version("1.0.0", "1.0.1"));
        assert!(!is_newer_version("0.9.0", "1.0.0"));
    }
}
