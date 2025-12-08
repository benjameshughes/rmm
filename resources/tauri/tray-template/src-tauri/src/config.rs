use std::path::PathBuf;

// Default interval constants (in seconds)
/// Default interval for collecting and submitting metrics
pub const DEFAULT_METRICS_INTERVAL_SECS: u64 = 60;

/// Default interval for checking agent status with backend
pub const DEFAULT_STATUS_CHECK_INTERVAL_SECS: u64 = 60;

/// Default interval for polling enrollment status during device approval
pub const DEFAULT_ENROLLMENT_POLL_INTERVAL_SECS: u64 = 30;

/// Default Netdata API base URL
pub const DEFAULT_NETDATA_URL: &str = "http://127.0.0.1:19999";

/// Default base URL placeholder (replaced at build time)
pub const DEFAULT_BASE_URL: &str = "{BASE_URL}";

/// Application configuration
#[derive(Debug, Clone)]
pub struct Config {
    /// Base URL of the Laravel backend (placeholder gets replaced at build time)
    pub base_url: String,
    /// Path to store agent data
    pub data_dir: PathBuf,
    /// Path to API key file
    pub key_file: PathBuf,
    /// Path to log file
    pub log_file: PathBuf,
    /// Metrics collection interval in seconds
    pub metrics_interval: u64,
    /// Status check interval in seconds
    pub status_check_interval: u64,
    /// Enrollment poll interval in seconds
    pub enrollment_poll_interval: u64,
    /// Netdata API base URL
    pub netdata_url: String,
}

impl Default for Config {
    fn default() -> Self {
        #[cfg(target_os = "windows")]
        let data_dir = PathBuf::from(r"C:\ProgramData\RMM");

        #[cfg(target_os = "macos")]
        let data_dir = {
            // Use user's Application Support directory (doesn't require root)
            dirs::data_dir()
                .map(|p| p.join("RMM"))
                .unwrap_or_else(|| PathBuf::from("/tmp/RMM"))
        };

        #[cfg(target_os = "linux")]
        let data_dir = PathBuf::from("/var/lib/rmm");

        let key_file = data_dir.join("agent.key");
        let log_file = data_dir.join("agent.log");

        Self {
            base_url: "{BASE_URL}".to_string(),
            data_dir,
            key_file,
            log_file,
            metrics_interval: DEFAULT_METRICS_INTERVAL_SECS,
            status_check_interval: DEFAULT_STATUS_CHECK_INTERVAL_SECS,
            enrollment_poll_interval: DEFAULT_ENROLLMENT_POLL_INTERVAL_SECS,
            netdata_url: DEFAULT_NETDATA_URL.to_string(),
        }
    }
}

impl Config {
    /// Create a new configuration with custom base URL
    pub fn new(base_url: String) -> Self {
        Self {
            base_url,
            ..Default::default()
        }
    }

    /// Create configuration with runtime config overrides applied
    pub fn with_runtime_config(runtime: &crate::runtime_config::RuntimeConfig) -> Self {
        let mut config = Self::default();

        // Apply overrides from runtime config
        config.base_url = runtime.effective_server_url(&config.base_url);
        config.netdata_url = runtime.effective_netdata_url(&config.netdata_url);
        config.metrics_interval = runtime.effective_metrics_interval(config.metrics_interval);

        config
    }

    /// Ensure data directory exists
    pub fn ensure_data_dir(&self) -> std::io::Result<()> {
        if !self.data_dir.exists() {
            std::fs::create_dir_all(&self.data_dir)?;
        }
        Ok(())
    }
}
