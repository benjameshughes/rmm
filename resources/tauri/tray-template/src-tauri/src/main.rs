// RMM Agent - Windows Service / Console Application
// No GUI - runs as a headless service managed via web panel

mod agent;
mod config;
mod enrollment;
mod metrics;
mod runtime_config;
mod storage;
mod sysinfo;

use agent::Agent;
use anyhow::{Context, Result};
use clap::{Parser, Subcommand};
use config::Config;
use runtime_config::RuntimeConfig;
use std::sync::Arc;
use tracing::{info, warn};

#[cfg(windows)]
use tracing::error;
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
    service_control_handler::{self, ServiceControlHandlerResult},
    service_dispatcher,
};

#[cfg(windows)]
const SERVICE_NAME: &str = "RMMAgent";
#[cfg(windows)]
const SERVICE_DISPLAY_NAME: &str = "RMM Monitoring Agent";
#[cfg(windows)]
const SERVICE_DESCRIPTION: &str = "Remote Monitoring and Management Agent - collects system metrics and enables remote management";

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
    // Load config
    let runtime_config = RuntimeConfig::load().unwrap_or_default();
    let config = Config::with_runtime_config(&runtime_config);

    // Initialize logging
    let _guard = init_logging(&config);

    info!("Windows service starting");

    // Create tokio runtime
    let rt = tokio::runtime::Runtime::new()?;

    // Create agent
    let agent = rt.block_on(async {
        Agent::with_config(config.clone()).await
    })?;
    let agent = Arc::new(agent);
    let agent_shutdown = agent.clone();

    // Register service control handler
    let event_handler = move |control_event| -> ServiceControlHandlerResult {
        match control_event {
            ServiceControl::Stop | ServiceControl::Shutdown => {
                info!("Service stop requested");
                agent_shutdown.shutdown();
                ServiceControlHandlerResult::NoError
            }
            ServiceControl::Interrogate => ServiceControlHandlerResult::NoError,
            _ => ServiceControlHandlerResult::NotImplemented,
        }
    };

    let status_handle = service_control_handler::register(SERVICE_NAME, event_handler)?;

    // Report running status
    status_handle.set_service_status(ServiceStatus {
        service_type: ServiceType::OWN_PROCESS,
        current_state: ServiceState::Running,
        controls_accepted: ServiceControlAccept::STOP | ServiceControlAccept::SHUTDOWN,
        exit_code: ServiceExitCode::Win32(0),
        checkpoint: 0,
        wait_hint: std::time::Duration::default(),
        process_id: None,
    })?;

    // Run the agent
    let result = rt.block_on(agent.run());

    if let Err(ref e) = result {
        error!("Agent error: {}", e);
    }

    // Report stopped status
    status_handle.set_service_status(ServiceStatus {
        service_type: ServiceType::OWN_PROCESS,
        current_state: ServiceState::Stopped,
        controls_accepted: ServiceControlAccept::empty(),
        exit_code: ServiceExitCode::Win32(if result.is_ok() { 0 } else { 1 }),
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

// Non-Windows stubs
#[cfg(not(windows))]
fn install_service() -> Result<()> {
    println!("Service installation is only supported on Windows");
    Ok(())
}

#[cfg(not(windows))]
fn uninstall_service() -> Result<()> {
    println!("Service uninstallation is only supported on Windows");
    Ok(())
}

#[cfg(not(windows))]
fn start_service_cmd() -> Result<()> {
    println!("Service control is only supported on Windows");
    Ok(())
}

#[cfg(not(windows))]
fn stop_service_cmd() -> Result<()> {
    println!("Service control is only supported on Windows");
    Ok(())
}

fn show_status() -> Result<()> {
    init_console_logging();

    let runtime_config = RuntimeConfig::load().unwrap_or_default();
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

fn main() -> Result<()> {
    let cli = Cli::parse();

    // Load runtime config
    let mut runtime_config = RuntimeConfig::load().unwrap_or_default();

    // Handle URL change detection
    if cli.url.is_some() {
        check_url_change(&mut runtime_config, cli.url.as_deref())?;
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
            // Console mode - run in foreground
            let _guard = init_logging(&config);

            let rt = tokio::runtime::Runtime::new()?;
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
                        eprintln!("  rmm --url <URL>      Set server URL");
                        eprintln!("  rmm --reset          Clear API key");
                    }
                }
            }

            #[cfg(not(windows))]
            {
                // On non-Windows, just run in console mode
                let _guard = init_logging(&config);
                let rt = tokio::runtime::Runtime::new()?;
                rt.block_on(run_agent(config))?;
            }
        }
    }

    Ok(())
}
