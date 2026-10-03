use std::path::PathBuf;

// Default interval constants (in seconds)
/// Default interval for collecting and submitting metrics
pub const DEFAULT_METRICS_INTERVAL_SECS: u64 = 60;

/// Default interval for heartbeat (lightweight check-in); the server can
/// change it in each heartbeat response
pub const DEFAULT_HEARTBEAT_INTERVAL_SECS: u64 = 15;

/// Shortest heartbeat interval the server may ask for
pub const MIN_HEARTBEAT_INTERVAL_SECS: u64 = 5;

/// Longest heartbeat interval the server may ask for
pub const MAX_HEARTBEAT_INTERVAL_SECS: u64 = 300;

/// Default interval for checking agent status with backend
pub const DEFAULT_STATUS_CHECK_INTERVAL_SECS: u64 = 60;

/// Default interval for polling enrollment status during device approval
pub const DEFAULT_ENROLLMENT_POLL_INTERVAL_SECS: u64 = 30;

/// Default interval for checking for updates (24 hours)
pub const DEFAULT_UPDATE_CHECK_INTERVAL_SECS: u64 = 86400;

/// Default interval for asking the server for queued commands
pub const DEFAULT_COMMAND_POLL_INTERVAL_SECS: u64 = 30;

/// Shortest timeout a command may run with
pub const DEFAULT_COMMAND_MIN_TIMEOUT_SECS: u64 = 10;

/// Longest timeout a command may run with (2 hours)
pub const DEFAULT_COMMAND_MAX_TIMEOUT_SECS: u64 = 7200;

/// Most characters of command output sent back (the server rejects more)
pub const DEFAULT_COMMAND_OUTPUT_LIMIT_CHARS: usize = 1_000_000;

/// HTTP timeout for command poll/report requests
pub const DEFAULT_COMMAND_REQUEST_TIMEOUT_SECS: u64 = 30;

/// How long to wait for a killed script's output pipes to close
pub const DEFAULT_COMMAND_DRAIN_GRACE_SECS: u64 = 5;

/// HTTP timeout for sleep/wake/boot power notices
pub const DEFAULT_POWER_REQUEST_TIMEOUT_SECS: u64 = 5;

/// HTTP timeout for the shutdown power notice, so it never holds up shutdown
pub const DEFAULT_POWER_SHUTDOWN_TIMEOUT_SECS: u64 = 3;

/// Longest the agent holds the machine awake after starting while it checks in
pub const DEFAULT_STARTUP_KEEP_AWAKE_MAX_SECS: u64 = 120;

/// How long the post-update restart script keeps the machine awake after
/// restarting the service, so the new agent can take over
pub const DEFAULT_RESTART_HANDOVER_GRACE_SECS: u64 = 30;

/// How often the agent checks it is really asleep; two ticks while marked
/// asleep mean the resume event was missed
pub const DEFAULT_ASLEEP_WATCHDOG_INTERVAL_SECS: u64 = 60;

/// Only announce a boot when the machine has been up for less than this
pub const DEFAULT_BOOT_NOTICE_MAX_UPTIME_SECS: u64 = 300;

/// Apps reported by CPU and by memory (Linux agent)
pub const DEFAULT_APPS_TOP_COUNT: usize = 10;

/// Least time between apt update simulations (Linux agent)
pub const DEFAULT_APT_CHECK_INTERVAL_SECS: u64 = 3600;

/// Longest a read-only health query command (systemctl, apt-get -s) may run
pub const DEFAULT_HEALTH_COMMAND_TIMEOUT_SECS: u64 = 30;

/// Default Netdata API base URL
pub const DEFAULT_NETDATA_URL: &str = "http://127.0.0.1:19999";

/// Netdata `group_by` that keeps each instance (volume, adapter, app) apart
pub const NETDATA_GROUP_BY_INSTANCE: &str = "instance,dimension";

/// Default base URL placeholder (replaced at build time)
pub const DEFAULT_BASE_URL: &str = "https://rmm.fnstr.uk";

/// GitHub releases API URL for auto-updates
pub const GITHUB_RELEASES_URL: &str = "https://api.github.com/repos/benjameshughes/rmm/releases/latest";

/// Current agent version (from Cargo.toml)
pub const AGENT_VERSION: &str = env!("CARGO_PKG_VERSION");

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
    /// Heartbeat interval in seconds
    pub heartbeat_interval: u64,
    /// Shortest heartbeat interval accepted from the server in seconds
    pub heartbeat_interval_min: u64,
    /// Longest heartbeat interval accepted from the server in seconds
    pub heartbeat_interval_max: u64,
    /// Status check interval in seconds
    pub status_check_interval: u64,
    /// Enrollment poll interval in seconds
    pub enrollment_poll_interval: u64,
    /// Update check interval in seconds
    pub update_check_interval: u64,
    /// Command poll interval in seconds
    pub command_poll_interval: u64,
    /// Shortest allowed command timeout in seconds
    pub command_min_timeout: u64,
    /// Longest allowed command timeout in seconds
    pub command_max_timeout: u64,
    /// Most characters of command output reported to the server
    pub command_output_limit: usize,
    /// HTTP timeout for command requests in seconds
    pub command_request_timeout: u64,
    /// Seconds to wait for a killed script's output pipes to close
    pub command_drain_grace: u64,
    /// HTTP timeout for sleep/wake/boot power notices in seconds
    pub power_request_timeout: u64,
    /// HTTP timeout for the shutdown power notice in seconds
    pub power_shutdown_timeout: u64,
    /// Most seconds the startup keep-awake is held
    pub startup_keep_awake_max: u64,
    /// Seconds the restart script stays awake after restarting the service
    pub restart_handover_grace: u64,
    /// Seconds between asleep watchdog ticks
    pub asleep_watchdog_interval: u64,
    /// Most system uptime in seconds at which a starting agent announces a boot
    pub boot_notice_max_uptime: u64,
    /// Apps reported by CPU and by memory (Linux agent)
    pub apps_top_count: usize,
    /// Seconds between apt update simulations (Linux agent)
    pub apt_check_interval: u64,
    /// Timeout in seconds for read-only health query commands
    pub health_command_timeout: u64,
    /// Skip automatic updates
    pub skip_updates: bool,
    /// Netdata API base URL
    pub netdata_url: String,
}

impl Default for Config {
    fn default() -> Self {
        #[cfg(target_os = "windows")]
        let data_dir = PathBuf::from(r"C:\ProgramData\BenJH RMM");

        #[cfg(target_os = "macos")]
        let data_dir = {
            // Use user's Application Support directory (doesn't require root)
            dirs::data_dir()
                .map(|p| p.join("RMM"))
                .unwrap_or_else(|| PathBuf::from("/tmp/RMM"))
        };

        #[cfg(target_os = "linux")]
        let data_dir = PathBuf::from("/var/lib/benjh-rmm");

        let key_file = data_dir.join("agent.key");
        let log_file = data_dir.join("agent.log");

        Self {
            base_url: DEFAULT_BASE_URL.to_string(),
            data_dir,
            key_file,
            log_file,
            metrics_interval: DEFAULT_METRICS_INTERVAL_SECS,
            heartbeat_interval: DEFAULT_HEARTBEAT_INTERVAL_SECS,
            heartbeat_interval_min: MIN_HEARTBEAT_INTERVAL_SECS,
            heartbeat_interval_max: MAX_HEARTBEAT_INTERVAL_SECS,
            status_check_interval: DEFAULT_STATUS_CHECK_INTERVAL_SECS,
            enrollment_poll_interval: DEFAULT_ENROLLMENT_POLL_INTERVAL_SECS,
            update_check_interval: DEFAULT_UPDATE_CHECK_INTERVAL_SECS,
            command_poll_interval: DEFAULT_COMMAND_POLL_INTERVAL_SECS,
            command_min_timeout: DEFAULT_COMMAND_MIN_TIMEOUT_SECS,
            command_max_timeout: DEFAULT_COMMAND_MAX_TIMEOUT_SECS,
            command_output_limit: DEFAULT_COMMAND_OUTPUT_LIMIT_CHARS,
            command_request_timeout: DEFAULT_COMMAND_REQUEST_TIMEOUT_SECS,
            command_drain_grace: DEFAULT_COMMAND_DRAIN_GRACE_SECS,
            power_request_timeout: DEFAULT_POWER_REQUEST_TIMEOUT_SECS,
            power_shutdown_timeout: DEFAULT_POWER_SHUTDOWN_TIMEOUT_SECS,
            startup_keep_awake_max: DEFAULT_STARTUP_KEEP_AWAKE_MAX_SECS,
            restart_handover_grace: DEFAULT_RESTART_HANDOVER_GRACE_SECS,
            asleep_watchdog_interval: DEFAULT_ASLEEP_WATCHDOG_INTERVAL_SECS,
            boot_notice_max_uptime: DEFAULT_BOOT_NOTICE_MAX_UPTIME_SECS,
            apps_top_count: DEFAULT_APPS_TOP_COUNT,
            apt_check_interval: DEFAULT_APT_CHECK_INTERVAL_SECS,
            health_command_timeout: DEFAULT_HEALTH_COMMAND_TIMEOUT_SECS,
            skip_updates: false,
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
