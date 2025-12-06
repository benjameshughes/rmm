use anyhow::{Context, Result};
use serde::Deserialize;
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

    /// Download and apply update
    pub async fn download_and_apply(&self, update: &UpdateInfo) -> Result<()> {
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

        let bytes = response.bytes().await.context("Failed to read update bytes")?;

        // Get current exe path and create update path
        let current_exe = std::env::current_exe().context("Failed to get current exe path")?;
        let update_exe = current_exe.with_extension("exe.new");
        let backup_exe = current_exe.with_extension("exe.bak");

        // Write new exe
        tokio::fs::write(&update_exe, &bytes)
            .await
            .context("Failed to write update file")?;

        // Backup current exe
        if backup_exe.exists() {
            tokio::fs::remove_file(&backup_exe).await.ok();
        }
        tokio::fs::rename(&current_exe, &backup_exe)
            .await
            .context("Failed to backup current exe")?;

        // Move new exe to current location
        tokio::fs::rename(&update_exe, &current_exe)
            .await
            .context("Failed to apply update")?;

        info!("Update applied successfully. Restart required.");
        Ok(())
    }

    pub fn current_version() -> &'static str {
        CURRENT_VERSION
    }
}

#[derive(Debug, Clone)]
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
