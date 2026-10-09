// RMM Agent - Windows Service / Console Application
// No GUI - runs as a headless service managed via web panel

mod agent;
mod backups;
mod command_runner;
mod commands;
mod config;
mod data_dir_security;
mod disk_usage;
mod enrollment;
mod keep_awake;
mod linux_health;
mod linux_service;
mod metrics;
mod power;
#[cfg(windows)]
mod power_events;
mod power_state;
#[cfg(windows)]
mod printer_spooler;
mod printers;
mod runtime_config;
mod startup_grace;
mod storage;
mod sysinfo;
mod updater;

use agent::Agent;
use anyhow::{Context, Result};
use clap::{Parser, Subcommand};
use config::Config;
use runtime_config::RuntimeConfig;
use std::sync::Arc;
use tracing::{info, warn};
use chrono::{Duration, Utc};

#[cfg(windows)]
use tracing::error;
#[cfg(windows)]
use power_state::PowerSignal;
use tracing_appender::non_blocking::WorkerGuard;
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt};

#[cfg(windows)]
use std::ffi::OsString;
#[cfg(windows)]
use windows_service::{
    define_windows_service,
    service::{
        ServiceControl, ServiceControlAccept, ServiceExitCode, ServiceState, ServiceStatus,
        ServiceType,
    },
    service_control_handler::{self, ServiceControlHandlerResult, ServiceStatusHandle},
    service_dispatcher,
};
#[cfg(windows)]
use std::sync::OnceLock;

#[cfg(windows)]
const SERVICE_NAME: &str = "BenJHRMM";
#[cfg(windows)]
const SERVICE_DISPLAY_NAME: &str = "BenJH RMM";
#[cfg(windows)]
const SERVICE_DESCRIPTION: &str = "BenJH Remote Monitoring and Management Agent - collects system metrics and enables remote management";

/// Service exit codes
#[cfg(windows)]
#[repr(u32)]
enum ServiceExitCodes {
    Success = 0,
    GeneralError = 1,
    EnrollmentFailed = 2,
    ConfigurationError = 3,
    NetdataUnavailable = 4,
}

/// What the control handler needs once the service starts stopping.
#[cfg(windows)]
#[derive(Default)]
struct StopContext {
    status_handle: OnceLock<ServiceStatusHandle>,
}

#[cfg(windows)]
impl StopContext {
    /// Report StopPending with no controls accepted. windows-service frees
    /// the handler closure after Stop/Shutdown/Preshutdown, so no further
    /// power events may reach it.
    fn begin_stopping(&self) {
        if let Some(status_handle) = self.status_handle.get() {
            let _ = status_handle.set_service_status(ServiceStatus {
                service_type: ServiceType::OWN_PROCESS,
                current_state: ServiceState::StopPending,
                controls_accepted: ServiceControlAccept::empty(),
                exit_code: ServiceExitCode::Win32(0),
                checkpoint: 1,
                wait_hint: std::time::Duration::from_secs(15),
                process_id: None,
            });
        }
    }
}

#[cfg(windows)]
impl ServiceExitCodes {
    fn from_error(error: &anyhow::Error) -> u32 {
        let error_msg = format!("{:?}", error);

        if error_msg.contains("enroll") || error_msg.contains("Enrollment") {
            Self::EnrollmentFailed as u32
        } else if error_msg.contains("config") || error_msg.contains("URL") || error_msg.contains("invalid") {
            Self::ConfigurationError as u32
        } else if error_msg.contains("netdata") || error_msg.contains("Netdata") {
            // Netdata unavailable is a warning, not a fatal error
            Self::Success as u32
        } else {
            Self::GeneralError as u32
        }
    }
}

/// RMM Agent - Remote Monitoring and Management Service
#[derive(Parser)]
#[command(name = "rmm")]
#[command(author, version, about, long_about = None)]
struct Cli {
    /// Server URL to connect to (saves to config for future runs)
    #[arg(long, value_name = "URL")]
    url: Option<String>,

    /// Clear API key and force re-enrollment
    #[arg(long)]
    reset: bool,

    #[command(subcommand)]
    command: Option<Commands>,
}

#[derive(Subcommand)]
enum Commands {
    /// Run in foreground (console mode for testing)
    Run,
    /// Install as Windows service
    Install,
    /// Uninstall Windows service
    Uninstall,
    /// Start the Windows service
    Start,
    /// Stop the Windows service
    Stop,
    /// Show current configuration and status
    Status,
    /// View agent logs
    Logs {
        /// Number of lines to show (default: 50)
        #[arg(short = 'n', long, default_value = "50")]
        lines: usize,
        /// Follow log output (like tail -f)
        #[arg(short, long)]
        follow: bool,
    },
    /// Force re-enrollment of this device
    Reenroll {
        /// Skip confirmation prompt
        #[arg(short, long)]
        force: bool,
    },
    /// Check for and apply updates
    Update {
        /// Only check for updates, don't download
        #[arg(long)]
        check: bool,
    },
    /// Scan disk usage under a folder and print one line of JSON
    Du(disk_usage::DuArgs),
}

/// Initialize logging and return the guard that must be kept alive
fn init_logging(config: &Config) -> WorkerGuard {
    // Create log directory if needed
    if let Some(parent) = config.log_file.parent() {
        let _ = std::fs::create_dir_all(parent);
    }

    // File appender for logs
    let file_appender = tracing_appender::rolling::daily(
        config.log_file.parent().unwrap_or(&config.data_dir),
        config
            .log_file
            .file_name()
            .and_then(|n| n.to_str())
            .unwrap_or("agent.log"),
    );

    let (non_blocking, guard) = tracing_appender::non_blocking(file_appender);

    // Set up subscriber with file output
    tracing_subscriber::registry()
        .with(
            tracing_subscriber::EnvFilter::try_from_default_env()
                .unwrap_or_else(|_| "info,reqwest=warn,hyper=warn".into()),
        )
        .with(tracing_subscriber::fmt::layer().with_writer(non_blocking))
        .init();

    info!("Logging initialized to {:?}", config.log_file);
    guard
}

/// Clean up log files older than 30 days
async fn cleanup_old_logs(config: &Config) {
    let log_dir = match config.log_file.parent() {
        Some(dir) => dir,
        None => return,
    };

    let cutoff_date = Utc::now() - Duration::days(30);

    match tokio::fs::read_dir(log_dir).await {
        Ok(mut entries) => {
            while let Ok(Some(entry)) = entries.next_entry().await {
                let path = entry.path();

                // Only process agent log files (agent.log and rolled agent.log.YYYY-MM-DD)
                if !is_agent_log_file(&path) {
                    continue;
                }

                // Check file modified time
                if let Ok(metadata) = entry.metadata().await {
                    if let Ok(modified) = metadata.modified() {
                        let modified_datetime = chrono::DateTime::<Utc>::from(modified);

                        if modified_datetime < cutoff_date {
                            if let Err(e) = tokio::fs::remove_file(&path).await {
                                warn!("Failed to delete old log file {:?}: {}", path, e);
                            } else {
                                info!("Deleted old log file: {:?}", path);
                            }
                        }
                    }
                }
            }
        }
        Err(e) => {
            warn!("Failed to read log directory for cleanup: {}", e);
        }
    }
}

/// True for `agent.log` and its daily rolled files (`agent.log.YYYY-MM-DD`).
fn is_agent_log_file(path: &std::path::Path) -> bool {
    path.file_name()
        .and_then(|n| n.to_str())
        .map(|n| n.starts_with("agent.log"))
        .unwrap_or(false)
}

/// Initialize console logging for status/install commands
fn init_console_logging() {
    let _ = tracing_subscriber::fmt()
        .with_env_filter("info,reqwest=warn,hyper=warn")
        .try_init();
}

/// Check if URL has changed and handle re-enrollment if needed
fn check_url_change(runtime_config: &mut RuntimeConfig, new_url: Option<&str>) -> Result<bool> {
    let config = Config::default();

    if let Some(url) = new_url {
        let stored_url = runtime_config.server_url.as_deref();

        if stored_url.is_some() && stored_url != Some(url) {
            // URL changed - clear API key to force re-enrollment
            warn!("Server URL changed from {:?} to {}", stored_url, url);
            warn!("Clearing API key to force re-enrollment");

            // Delete the API key file
            if config.key_file.exists() {
                std::fs::remove_file(&config.key_file)
                    .context("Failed to delete API key file")?;
                info!("API key cleared due to URL change");
            }
        }

        // Save new URL
        runtime_config.server_url = Some(url.to_string());
        runtime_config.save()?;

        return Ok(true);
    }

    Ok(false)
}

/// Run the agent (blocking - used by both console and service modes)
async fn run_agent(config: Config) -> Result<()> {
    info!("=== RMM Agent Starting ===");
    info!("Version: {}", env!("CARGO_PKG_VERSION"));
    info!("Server URL: {}", config.base_url);

    let agent = Arc::new(Agent::with_config(config).await?);

    // Set up Ctrl+C handler for graceful shutdown
    let agent_shutdown = agent.clone();

    tokio::spawn(async move {
        tokio::signal::ctrl_c().await.ok();
        info!("Shutdown signal received");
        agent_shutdown.shutdown();
    });

    // Run the agent (blocks until cancelled)
    agent.run().await?;

    info!("Agent stopped");
    Ok(())
}

// ============================================================================
// Windows Service Implementation
// ============================================================================

#[cfg(windows)]
define_windows_service!(ffi_service_main, service_main);

#[cfg(windows)]
fn service_main(_arguments: Vec<OsString>) {
    if let Err(e) = run_service() {
        error!("Service failed: {}", e);
    }
}

#[cfg(windows)]
fn run_service() -> Result<()> {
    // Lock down the data directory BEFORE anything in it is read (config.json,
    // agent.key) or opened for writing (logs). This also deletes the legacy
    // update\ staging directory. Paths here do not depend on config.json.
    let default_config = Config::default();
    let hardening = data_dir_security::secure_data_dir(&default_config.data_dir);

    // Initialize logging (log location is fixed, not taken from config.json)
    let _guard = init_logging(&default_config);

    info!("Windows service starting (v{})", env!("CARGO_PKG_VERSION"));
    hardening.log();

    // Load config (only after the directory is locked down)
    let runtime_config = match RuntimeConfig::load() {
        Ok(rc) => rc,
        Err(e) => {
            warn!("Ignoring unreadable runtime config: {:#}", e);
            RuntimeConfig::default()
        }
    };
    let config = Config::with_runtime_config(&runtime_config);

    // Create tokio runtime
    let rt = tokio::runtime::Runtime::new()?;

    // Clean up old logs (async, non-blocking)
    let config_clone = config.clone();
    rt.spawn(async move {
        cleanup_old_logs(&config_clone).await;
    });

    // Create agent
    let agent = rt.block_on(async {
        Agent::with_config(config.clone()).await
    })?;
    let agent = Arc::new(agent);
    let agent_shutdown = agent.clone();
    let power = agent.power();
    let stop_context = Arc::new(StopContext::default());
    let handler_stop_context = stop_context.clone();

    // Register service control handler. It must return at once: power events
    // only update shared state; notices go out from the agent's runtime.
    let event_handler = move |control_event| -> ServiceControlHandlerResult {
        match control_event {
            ServiceControl::Stop => {
                info!("Service stop requested");
                handler_stop_context.begin_stopping();
                agent_shutdown.shutdown();
                ServiceControlHandlerResult::NoError
            }
            // The agent stops itself once the shutdown notice is sent.
            ServiceControl::Preshutdown | ServiceControl::Shutdown => {
                info!("System shutdown requested");
                handler_stop_context.begin_stopping();
                power.signal(PowerSignal::Shutdown);
                ServiceControlHandlerResult::NoError
            }
            ServiceControl::PowerEvent(event) => {
                if let Some(signal) = power_events::signal_from_power_event(&event) {
                    power.signal(signal);
                }
                ServiceControlHandlerResult::NoError
            }
            ServiceControl::Interrogate => ServiceControlHandlerResult::NoError,
            _ => ServiceControlHandlerResult::NotImplemented,
        }
    };

    let status_handle = service_control_handler::register(SERVICE_NAME, event_handler)?;
    let _ = stop_context.status_handle.set(status_handle);

    // Report running status. PRESHUTDOWN replaces SHUTDOWN (windows-service
    // documents them as mutually exclusive) and comes early enough to tell
    // the server before the network goes.
    status_handle.set_service_status(ServiceStatus {
        service_type: ServiceType::OWN_PROCESS,
        current_state: ServiceState::Running,
        controls_accepted: ServiceControlAccept::STOP
            | ServiceControlAccept::PRESHUTDOWN
            | ServiceControlAccept::POWER_EVENT,
        exit_code: ServiceExitCode::Win32(0),
        checkpoint: 0,
        wait_hint: std::time::Duration::default(),
        process_id: None,
    })?;

    // Run the agent
    let result = rt.block_on(agent.run());

    let exit_code = match &result {
        Ok(_) => {
            info!("Agent stopped normally");
            ServiceExitCodes::Success as u32
        }
        Err(e) => {
            error!("Agent error: {}", e);
            ServiceExitCodes::from_error(e)
        }
    };

    // Report stopped status
    status_handle.set_service_status(ServiceStatus {
        service_type: ServiceType::OWN_PROCESS,
        current_state: ServiceState::Stopped,
        controls_accepted: ServiceControlAccept::empty(),
        exit_code: ServiceExitCode::Win32(exit_code),
        checkpoint: 0,
        wait_hint: std::time::Duration::default(),
        process_id: None,
    })?;

    Ok(())
}

#[cfg(windows)]
fn install_service() -> Result<()> {
    use std::ffi::OsStr;
    use windows_service::{
        service::{ServiceAccess, ServiceErrorControl, ServiceInfo, ServiceStartType},
        service_manager::{ServiceManager, ServiceManagerAccess},
    };

    init_console_logging();

    let manager = ServiceManager::local_computer(
        None::<&str>,
        ServiceManagerAccess::CREATE_SERVICE,
    )?;

    let service_binary = std::env::current_exe()?;

    let service_info = ServiceInfo {
        name: OsString::from(SERVICE_NAME),
        display_name: OsString::from(SERVICE_DISPLAY_NAME),
        service_type: ServiceType::OWN_PROCESS,
        start_type: ServiceStartType::AutoStart,
        error_control: ServiceErrorControl::Normal,
        executable_path: service_binary,
        launch_arguments: vec![],
        dependencies: vec![],
        account_name: None, // LocalSystem
        account_password: None,
    };

    let service = manager.create_service(&service_info, ServiceAccess::CHANGE_CONFIG)?;

    // Set description
    service.set_description(SERVICE_DESCRIPTION)?;

    info!("Service '{}' installed successfully", SERVICE_NAME);
    println!("Service '{}' installed successfully", SERVICE_NAME);
    println!("Run 'rmm-agent start' to start the service");

    Ok(())
}

#[cfg(windows)]
fn uninstall_service() -> Result<()> {
    use windows_service::{
        service::ServiceAccess,
        service_manager::{ServiceManager, ServiceManagerAccess},
    };

    init_console_logging();

    let manager = ServiceManager::local_computer(
        None::<&str>,
        ServiceManagerAccess::CONNECT,
    )?;

    let service = manager.open_service(SERVICE_NAME, ServiceAccess::DELETE)?;
    service.delete()?;

    info!("Service '{}' uninstalled successfully", SERVICE_NAME);
    println!("Service '{}' uninstalled successfully", SERVICE_NAME);

    Ok(())
}

#[cfg(windows)]
fn start_service_cmd() -> Result<()> {
    use windows_service::{
        service::ServiceAccess,
        service_manager::{ServiceManager, ServiceManagerAccess},
    };

    init_console_logging();

    let manager = ServiceManager::local_computer(
        None::<&str>,
        ServiceManagerAccess::CONNECT,
    )?;

    let service = manager.open_service(SERVICE_NAME, ServiceAccess::START)?;
    service.start::<String>(&[])?;

    println!("Service '{}' started", SERVICE_NAME);

    Ok(())
}

#[cfg(windows)]
fn stop_service_cmd() -> Result<()> {
    use windows_service::{
        service::ServiceAccess,
        service_manager::{ServiceManager, ServiceManagerAccess},
    };

    init_console_logging();

    let manager = ServiceManager::local_computer(
        None::<&str>,
        ServiceManagerAccess::CONNECT,
    )?;

    let service = manager.open_service(SERVICE_NAME, ServiceAccess::STOP)?;
    service.stop()?;

    println!("Service '{}' stopped", SERVICE_NAME);

    Ok(())
}

// Linux: systemd service (monitor-only agent)
#[cfg(target_os = "linux")]
fn install_service() -> Result<()> {
    linux_service::install()
}

#[cfg(target_os = "linux")]
fn uninstall_service() -> Result<()> {
    linux_service::uninstall()
}

#[cfg(target_os = "linux")]
fn start_service_cmd() -> Result<()> {
    linux_service::start()
}

#[cfg(target_os = "linux")]
fn stop_service_cmd() -> Result<()> {
    linux_service::stop()
}

// Other platforms (macOS development builds)
#[cfg(not(any(windows, target_os = "linux")))]
fn install_service() -> Result<()> {
    println!("Service installation is only supported on Windows and Linux");
    Ok(())
}

#[cfg(not(any(windows, target_os = "linux")))]
fn uninstall_service() -> Result<()> {
    println!("Service uninstallation is only supported on Windows and Linux");
    Ok(())
}

#[cfg(not(any(windows, target_os = "linux")))]
fn start_service_cmd() -> Result<()> {
    println!("Service control is only supported on Windows and Linux");
    Ok(())
}

#[cfg(not(any(windows, target_os = "linux")))]
fn stop_service_cmd() -> Result<()> {
    println!("Service control is only supported on Windows and Linux");
    Ok(())
}

/// Lock the Linux data directory (root only, 0700) before anything in it is
/// read or the log is opened. Elsewhere the Windows service does its own.
fn harden_data_dir(config: &Config) -> Option<data_dir_security::HardeningReport> {
    if cfg!(target_os = "linux") {
        Some(data_dir_security::secure_data_dir(&config.data_dir))
    } else {
        None
    }
}

fn show_status() -> Result<()> {
    init_console_logging();

    let runtime_config = match RuntimeConfig::load() {
        Ok(rc) => rc,
        Err(e) if data_dir_security::is_permission_denied(&e) => return Err(e),
        Err(e) => {
            eprintln!("Warning: ignoring unreadable runtime config: {:#}", e);
            RuntimeConfig::default()
        }
    };
    let config = Config::with_runtime_config(&runtime_config);

    println!("RMM Agent Status");
    println!("================");
    println!("Version: {}", env!("CARGO_PKG_VERSION"));
    println!("Server URL: {}", config.base_url);
    println!("Netdata URL: {}", config.netdata_url);
    println!("Data Directory: {}", config.data_dir.display());
    println!("Log File: {}", config.log_file.display());
    println!("API Key File: {}", config.key_file.display());
    println!();

    // Check if enrolled
    if config.key_file.exists() {
        println!("Enrollment: Yes (API key exists)");
    } else {
        println!("Enrollment: No (will enroll on next run)");
    }

    // Check if runtime config has overrides
    if runtime_config.server_url.is_some() {
        println!("Server URL Override: {}", runtime_config.server_url.as_ref().unwrap());
    }

    Ok(())
}

fn show_logs(config: &Config, lines: usize, follow: bool) -> Result<()> {
    use std::io::{BufRead, BufReader, Seek, SeekFrom};
    use std::thread;
    use std::time::Duration;

    let log_dir = config.log_file.parent().unwrap_or(&config.data_dir);

    // Find the most recent log file (rolling logs may have dates)
    let log_file = if config.log_file.exists() {
        config.log_file.clone()
    } else {
        // Look for dated log files
        let mut log_files: Vec<_> = std::fs::read_dir(log_dir)
            .ok()
            .map(|entries| {
                entries
                    .filter_map(|e| e.ok())
                    .filter(|e| {
                        e.path()
                            .file_name()
                            .and_then(|n| n.to_str())
                            .map(|n| n.starts_with("agent.log"))
                            .unwrap_or(false)
                    })
                    .collect()
            })
            .unwrap_or_default();

        log_files.sort_by_key(|e| e.metadata().and_then(|m| m.modified()).ok());

        log_files
            .last()
            .map(|e| e.path())
            .unwrap_or_else(|| config.log_file.clone())
    };

    if !log_file.exists() {
        println!("No log file found at {:?}", log_file);
        println!("The service may not have started yet.");
        return Ok(());
    }

    println!("Reading logs from: {:?}\n", log_file);

    if follow {
        // Follow mode - tail -f style
        let file = std::fs::File::open(&log_file)?;
        let mut reader = BufReader::new(file);

        // First, print last N lines
        let content = std::fs::read_to_string(&log_file)?;
        let all_lines: Vec<&str> = content.lines().collect();
        let start = all_lines.len().saturating_sub(lines);
        for line in &all_lines[start..] {
            println!("{}", line);
        }

        // Seek to end for following
        reader.seek(SeekFrom::End(0))?;

        println!("\n--- Following log (Ctrl+C to stop) ---\n");

        loop {
            let mut line = String::new();
            match reader.read_line(&mut line) {
                Ok(0) => {
                    // No new data, wait a bit
                    thread::sleep(Duration::from_millis(100));
                }
                Ok(_) => {
                    print!("{}", line);
                }
                Err(e) => {
                    eprintln!("Error reading log: {}", e);
                    break;
                }
            }
        }
    } else {
        // Static mode - show last N lines
        let content = std::fs::read_to_string(&log_file)?;
        let all_lines: Vec<&str> = content.lines().collect();
        let start = all_lines.len().saturating_sub(lines);

        for line in &all_lines[start..] {
            println!("{}", line);
        }
    }

    Ok(())
}

fn reenroll_device(force: bool) -> Result<()> {
    use std::io::Write;

    if !force {
        println!("This will:");
        println!("  1. Stop the RMM service");
        println!("  2. Clear the stored API key");
        println!("  3. Restart the service for fresh enrollment");
        println!();
        print!("Continue? [y/N] ");
        std::io::stdout().flush()?;

        let mut input = String::new();
        std::io::stdin().read_line(&mut input)?;
        if !input.trim().eq_ignore_ascii_case("y") {
            println!("Cancelled.");
            return Ok(());
        }
    }

    let config = Config::default();

    // 1. Stop service
    println!("Stopping service...");
    #[cfg(target_os = "linux")]
    {
        let _ = linux_service::stop();
    }
    #[cfg(windows)]
    {
        let _ = std::process::Command::new("sc")
            .args(["stop", SERVICE_NAME])
            .output();
        std::thread::sleep(std::time::Duration::from_secs(2));
    }

    // 2. Clear API key
    if config.key_file.exists() {
        std::fs::remove_file(&config.key_file)?;
        println!("API key cleared.");
    } else {
        println!("No API key found (already cleared).");
    }

    // 3. Restart service
    println!("Starting service...");
    #[cfg(target_os = "linux")]
    {
        let _ = linux_service::start();
    }
    #[cfg(windows)]
    {
        let _ = std::process::Command::new("sc")
            .args(["start", SERVICE_NAME])
            .output();
    }

    println!();
    println!("Re-enrollment initiated!");
    println!("The device will appear as 'pending' in the web panel.");
    println!("Approve it to complete re-enrollment.");

    Ok(())
}

fn check_for_updates(check_only: bool) -> Result<()> {
    use crate::config::AGENT_VERSION;

    init_console_logging();

    let runtime_config = RuntimeConfig::load().unwrap_or_default();
    let config = Config::with_runtime_config(&runtime_config);

    println!("RMM Agent Update");
    println!("================");
    println!("Current version: {}", AGENT_VERSION);
    println!();

    let updater = updater::Updater::new(config)?;
    let rt = tokio::runtime::Runtime::new()?;

    rt.block_on(async {
        match updater.check_only().await {
            Ok(Some(info)) => {
                println!("Update available: v{}", info.version);
                println!("Download URL: {}", info.download_url);
                if let Some(size) = info.size {
                    println!("Size: {:.2} MB", size as f64 / 1024.0 / 1024.0);
                }

                if !check_only {
                    println!();
                    println!("Downloading and verifying update...");
                    match updater.download_and_install(&info).await {
                        Ok(path) => {
                            println!("Installed v{} to {}", info.version, path.display());
                            println!("Restarting the service to load it...");
                            if let Err(e) = updater.trigger_restart() {
                                eprintln!("Restart failed: {}", e);
                                println!("Restart the service manually: rmm stop && rmm start");
                            }
                        }
                        Err(e) => {
                            if data_dir_security::is_permission_denied(&e) {
                                eprintln!("Update failed: access denied.");
                                eprintln!("Run this command from an elevated (Administrator) prompt.");
                            } else {
                                eprintln!("Update failed: {:#}", e);
                            }
                        }
                    }
                }
            }
            Ok(None) => {
                println!("You are running the latest version.");
            }
            Err(e) => {
                eprintln!("Update check failed: {:#}", e);
            }
        }
    });

    Ok(())
}

/// Commands that read or write the data directory (config, key, logs).
/// After lockdown these require an elevated prompt on Windows.
fn needs_data_dir_access(cli: &Cli) -> bool {
    cli.url.is_some()
        || cli.reset
        || matches!(
            cli.command,
            Some(Commands::Run)
                | Some(Commands::Status)
                | Some(Commands::Logs { .. })
                | Some(Commands::Reenroll { .. })
        )
}

fn main() {
    let cli = Cli::parse();
    let data_dir = Config::default().data_dir;

    if needs_data_dir_access(&cli) {
        if let Err(e) = data_dir_security::check_data_dir_access(&data_dir) {
            if e.kind() == std::io::ErrorKind::PermissionDenied {
                eprintln!("{}", data_dir_security::elevation_message(&data_dir));
            } else {
                eprintln!("Cannot access {}: {}", data_dir.display(), e);
            }
            std::process::exit(1);
        }
    }

    if let Err(e) = run_cli(cli) {
        if data_dir_security::is_permission_denied(&e) {
            eprintln!("Error: {:#}", e);
            eprintln!();
            eprintln!("{}", data_dir_security::elevation_message(&data_dir));
        } else {
            eprintln!("Error: {:#}", e);
        }
        std::process::exit(1);
    }
}

fn run_cli(cli: Cli) -> Result<()> {
    // Load runtime config
    let mut runtime_config = RuntimeConfig::load().unwrap_or_default();

    // Handle URL change detection
    if cli.url.is_some() {
        check_url_change(&mut runtime_config, cli.url.as_deref())?;
        // If no subcommand given, just print success and exit
        if cli.command.is_none() {
            println!("Server URL set to: {}", cli.url.as_ref().unwrap());
            return Ok(());
        }
    }

    // Handle reset flag
    if cli.reset {
        let config = Config::default();
        if config.key_file.exists() {
            std::fs::remove_file(&config.key_file)?;
            println!("API key cleared. Agent will re-enroll on next run.");
        } else {
            println!("No API key to clear.");
        }
        return Ok(());
    }

    // Build config with any overrides
    let config = Config::with_runtime_config(&runtime_config);

    match cli.command {
        Some(Commands::Run) => {
            // Console mode - run in foreground (also how systemd runs it)
            let hardening = harden_data_dir(&config);
            let _guard = init_logging(&config);
            if let Some(report) = hardening {
                report.log();
            }

            let rt = tokio::runtime::Runtime::new()?;

            // Clean up old logs (async, non-blocking)
            let config_clone = config.clone();
            rt.spawn(async move {
                cleanup_old_logs(&config_clone).await;
            });

            rt.block_on(run_agent(config))?;
        }
        Some(Commands::Install) => {
            install_service()?;
        }
        Some(Commands::Uninstall) => {
            uninstall_service()?;
        }
        Some(Commands::Start) => {
            start_service_cmd()?;
        }
        Some(Commands::Stop) => {
            stop_service_cmd()?;
        }
        Some(Commands::Status) => {
            show_status()?;
        }
        Some(Commands::Logs { lines, follow }) => {
            show_logs(&config, lines, follow)?;
        }
        Some(Commands::Reenroll { force }) => {
            reenroll_device(force)?;
        }
        Some(Commands::Update { check }) => {
            check_for_updates(check)?;
        }
        Some(Commands::Du(args)) => {
            std::process::exit(disk_usage::run(&args));
        }
        None => {
            // No command - check if we're being run as a service
            #[cfg(windows)]
            {
                // Try to run as Windows service
                // If this fails, it means we're not being run by the SCM
                match service_dispatcher::start(SERVICE_NAME, ffi_service_main) {
                    Ok(_) => {}
                    Err(e) => {
                        // Not running as service - show help
                        eprintln!("Not running as a service. Error: {}", e);
                        eprintln!();
                        eprintln!("Usage:");
                        eprintln!("  rmm run              Run in foreground (console mode)");
                        eprintln!("  rmm install          Install as Windows service");
                        eprintln!("  rmm uninstall        Uninstall Windows service");
                        eprintln!("  rmm start            Start the service");
                        eprintln!("  rmm stop             Stop the service");
                        eprintln!("  rmm status           Show configuration");
                        eprintln!("  rmm logs             View agent logs");
                        eprintln!("  rmm reenroll         Force re-enrollment");
                        eprintln!("  rmm update           Check for and apply updates");
                        eprintln!("  rmm update --check   Only check for updates");
                        eprintln!("  rmm du <PATH>        Scan disk usage (JSON)");
                        eprintln!("  rmm --url <URL>      Set server URL");
                        eprintln!("  rmm --reset          Clear API key");
                    }
                }
            }

            #[cfg(not(windows))]
            {
                // On non-Windows, just run in console mode
                let hardening = harden_data_dir(&config);
                let _guard = init_logging(&config);
                if let Some(report) = hardening {
                    report.log();
                }
                let rt = tokio::runtime::Runtime::new()?;

                // Clean up old logs (async, non-blocking)
                let config_clone = config.clone();
                rt.spawn(async move {
                    cleanup_old_logs(&config_clone).await;
                });

                rt.block_on(run_agent(config))?;
            }
        }
    }

    Ok(())
}

#[cfg(test)]
mod cli_contract_tests {
    use super::*;

    // resources/scripts/installer/install.sh runs exactly this; --url is a
    // top-level flag, so it must come before the subcommand.
    #[test]
    fn accepts_the_linux_installer_invocation() {
        let cli = Cli::try_parse_from(["rmm", "--url", "https://rmm.example", "install"])
            .expect("installer invocation must parse");

        assert_eq!(cli.url.as_deref(), Some("https://rmm.example"));
        assert!(matches!(cli.command, Some(Commands::Install)));
    }

    #[test]
    fn rejects_url_after_the_subcommand() {
        assert!(Cli::try_parse_from(["rmm", "install", "--url", "https://rmm.example"]).is_err());
    }
}
