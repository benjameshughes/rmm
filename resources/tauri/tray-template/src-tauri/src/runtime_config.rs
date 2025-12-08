use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use std::path::PathBuf;
use tracing::{debug, info};

/// Runtime configuration that can be changed at runtime and persists across restarts
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RuntimeConfig {
    /// Optional server URL override
    pub server_url: Option<String>,
    /// Optional Netdata URL override
    pub netdata_url: Option<String>,
    /// Optional metrics interval override (in seconds)
    pub metrics_interval: Option<u64>,
}

impl Default for RuntimeConfig {
    fn default() -> Self {
        Self {
            server_url: None,
            netdata_url: None,
            metrics_interval: None,
        }
    }
}

impl RuntimeConfig {
    /// Get the config file path based on platform
    fn config_path() -> PathBuf {
        #[cfg(target_os = "windows")]
        let config_dir = PathBuf::from(r"C:\ProgramData\RMM");

        #[cfg(target_os = "macos")]
        let config_dir = PathBuf::from("/Library/Application Support/RMM");

        #[cfg(target_os = "linux")]
        let config_dir = PathBuf::from("/var/lib/rmm");

        config_dir.join("config.json")
    }

    /// Load runtime config from disk
    pub fn load() -> Result<Self> {
        let path = Self::config_path();

        if !path.exists() {
            debug!("Runtime config file does not exist, using defaults");
            return Ok(Self::default());
        }

        let content = std::fs::read_to_string(&path)
            .context("Failed to read runtime config file")?;

        let config: RuntimeConfig = serde_json::from_str(&content)
            .context("Failed to parse runtime config")?;

        info!("Runtime config loaded from {:?}", path);
        Ok(config)
    }

    /// Save runtime config to disk
    pub fn save(&self) -> Result<()> {
        let path = Self::config_path();

        // Ensure directory exists
        if let Some(parent) = path.parent() {
            std::fs::create_dir_all(parent)
                .context("Failed to create config directory")?;
        }

        let content = serde_json::to_string_pretty(self)
            .context("Failed to serialize runtime config")?;

        std::fs::write(&path, content)
            .context("Failed to write runtime config file")?;

        info!("Runtime config saved to {:?}", path);
        Ok(())
    }

    /// Get the effective server URL (override or default)
    pub fn effective_server_url(&self, default: &str) -> String {
        self.server_url
            .as_ref()
            .map(|s| s.clone())
            .unwrap_or_else(|| default.to_string())
    }

    /// Get the effective Netdata URL (override or default)
    pub fn effective_netdata_url(&self, default: &str) -> String {
        self.netdata_url
            .as_ref()
            .map(|s| s.clone())
            .unwrap_or_else(|| default.to_string())
    }

    /// Get the effective metrics interval (override or default)
    pub fn effective_metrics_interval(&self, default: u64) -> u64 {
        self.metrics_interval.unwrap_or(default)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_default_config() {
        let config = RuntimeConfig::default();
        assert!(config.server_url.is_none());
        assert!(config.netdata_url.is_none());
        assert!(config.metrics_interval.is_none());
    }

    #[test]
    fn test_effective_values() {
        let config = RuntimeConfig {
            server_url: Some("https://custom.example.com".to_string()),
            netdata_url: None,
            metrics_interval: Some(120),
        };

        assert_eq!(
            config.effective_server_url("https://default.example.com"),
            "https://custom.example.com"
        );
        assert_eq!(
            config.effective_netdata_url("http://localhost:19999"),
            "http://localhost:19999"
        );
        assert_eq!(config.effective_metrics_interval(60), 120);
    }
}
