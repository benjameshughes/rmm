use std::path::PathBuf;

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
        let data_dir = PathBuf::from("/Library/Application Support/RMM");

        #[cfg(target_os = "linux")]
        let data_dir = PathBuf::from("/var/lib/rmm");

        let key_file = data_dir.join("agent.key");
        let log_file = data_dir.join("agent.log");

        Self {
            base_url: "{BASE_URL}".to_string(),
            data_dir,
            key_file,
            log_file,
            metrics_interval: 60,
            status_check_interval: 60,
            enrollment_poll_interval: 30,
            netdata_url: "http://127.0.0.1:19999".to_string(),
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

    /// Ensure data directory exists
    pub fn ensure_data_dir(&self) -> std::io::Result<()> {
        if !self.data_dir.exists() {
            std::fs::create_dir_all(&self.data_dir)?;
        }
        Ok(())
    }
}
