#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

mod agent;
mod config;
mod enrollment;
mod metrics;
mod storage;
mod sysinfo;
mod updater;

use agent::{Agent, AgentState};
use config::Config;
use std::sync::Arc;
use tauri::{
    api::notification::Notification, CustomMenuItem, Manager, SystemTray, SystemTrayEvent,
    SystemTrayMenu, SystemTrayMenuItem,
};
use tokio::sync::RwLock;
use tracing::{error, info, warn};
use updater::{UpdateInfo, Updater};
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt};

/// Initialize logging and return the guard that must be kept alive
fn init_logging(config: &Config) -> tracing_appender::non_blocking::WorkerGuard {
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

    // Return the guard so it lives for the entire application lifetime
    guard
}

/// Build the system tray menu
fn build_tray_menu() -> SystemTrayMenu {
    SystemTrayMenu::new()
        .add_item(CustomMenuItem::new("status", "Status: Initializing...").disabled())
        .add_item(CustomMenuItem::new("version", format!("Version: {}", env!("CARGO_PKG_VERSION"))).disabled())
        .add_native_item(SystemTrayMenuItem::Separator)
        .add_item(CustomMenuItem::new("check_update", "Check for Updates"))
        .add_item(CustomMenuItem::new("install", "Install / Repair Agent"))
        .add_item(CustomMenuItem::new("open_log", "Open Agent Log"))
        .add_item(CustomMenuItem::new("open_panel", "Open Panel"))
        .add_native_item(SystemTrayMenuItem::Separator)
        .add_item(CustomMenuItem::new("quit", "Quit"))
}

/// Update the tray status label
fn update_tray_status(app_handle: &tauri::AppHandle, state: &AgentState) {
    let tray = app_handle.tray_handle();
    let status_item = tray.get_item("status");
    let _ = status_item.set_title(&state.status_label());
}

#[tauri::command]
async fn get_agent_state(agent: tauri::State<'_, Arc<Agent>>) -> Result<String, String> {
    let state = agent.get_state().await;
    Ok(state.as_display())
}

#[tauri::command]
async fn get_system_info(agent: tauri::State<'_, Arc<Agent>>) -> Result<String, String> {
    Ok(agent.system_info().summary())
}

fn main() {
    // Initialize configuration
    let config = Config::default();

    // Initialize logging - keep guard alive for application lifetime
    let _log_guard = init_logging(&config);

    info!("=== RMM Agent Starting ===");
    info!("Version: {}", env!("CARGO_PKG_VERSION"));
    info!("Base URL: {}", config.base_url);

    // Build system tray
    let tray = SystemTray::new().with_menu(build_tray_menu());

    tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![get_agent_state, get_system_info])
        .system_tray(tray)
        .on_system_tray_event(|app, event| {
            let config = Config::default();
            match event {
                SystemTrayEvent::LeftClick { .. } => {
                    // Menu shows on left click via config
                }
                SystemTrayEvent::MenuItemClick { id, .. } => match id.as_str() {
                    "status" => {
                        // Get current state and show notification
                        let app_handle = app.app_handle();
                        tauri::async_runtime::spawn(async move {
                            if let Some(agent) = app_handle.try_state::<Arc<Agent>>() {
                                let state = agent.get_state().await;
                                let body = state.as_display();

                                info!("Status requested: {}", body);

                                // Show notification (Tauri v1 API)
                                if let Err(e) = Notification::new(&app_handle.config().tauri.bundle.identifier)
                                    .title("RMM Agent Status")
                                    .body(&body)
                                    .show() {
                                    error!("Failed to show notification: {}", e);
                                }
                            }
                        });
                    }
                    "check_update" => {
                        info!("Checking for updates...");
                        let app_handle = app.app_handle();
                        tauri::async_runtime::spawn(async move {
                            match Updater::new() {
                                Ok(updater) => {
                                    match updater.check_for_update().await {
                                        Ok(Some(update)) => {
                                            info!("Update available: {} -> {}", update.current_version, update.latest_version);

                                            // Show notification
                                            let body = format!(
                                                "Version {} is available (current: {}). Click Install/Repair to update.",
                                                update.latest_version, update.current_version
                                            );
                                            let _ = Notification::new(&app_handle.config().tauri.bundle.identifier)
                                                .title("Update Available")
                                                .body(&body)
                                                .show();

                                            // Store update info for later
                                            if let Some(update_state) = app_handle.try_state::<Arc<RwLock<Option<UpdateInfo>>>>() {
                                                *update_state.write().await = Some(update);
                                            }

                                            // Update menu item to show update available
                                            let tray = app_handle.tray_handle();
                                            let _ = tray.get_item("check_update").set_title("Update Available!");
                                        }
                                        Ok(None) => {
                                            info!("No updates available");
                                            let _ = Notification::new(&app_handle.config().tauri.bundle.identifier)
                                                .title("No Updates")
                                                .body("You're running the latest version.")
                                                .show();
                                        }
                                        Err(e) => {
                                            warn!("Update check failed: {}", e);
                                            let _ = Notification::new(&app_handle.config().tauri.bundle.identifier)
                                                .title("Update Check Failed")
                                                .body(&format!("Could not check for updates: {}", e))
                                                .show();
                                        }
                                    }
                                }
                                Err(e) => {
                                    error!("Failed to create updater: {}", e);
                                }
                            }
                        });
                    }
                    "install" => {
                        // Re-trigger enrollment check by spawning new enrollment
                        info!("Install/Repair requested - triggering enrollment check");
                        let app_handle = app.app_handle();
                        tauri::async_runtime::spawn(async move {
                            if let Some(agent) = app_handle.try_state::<Arc<Agent>>() {
                                let state = agent.get_state().await;
                                info!("Current state before install/repair: {:?}", state);

                                // For now, just check status which will update tray
                                if let Err(e) = agent.check_status().await {
                                    error!("Status check failed during install/repair: {}", e);
                                }
                            }
                        });
                    }
                    "open_log" => {
                        // Open the log directory in file explorer
                        info!("Opening log directory: {:?}", config.log_file.parent());

                        if let Some(log_dir) = config.log_file.parent() {
                            let log_dir_str = log_dir.to_string_lossy().to_string();

                            #[cfg(target_os = "windows")]
                            {
                                // On Windows, open Explorer to the directory
                                let _ = tauri::api::shell::open(&app.shell_scope(), &log_dir_str, None);
                            }

                            #[cfg(target_os = "macos")]
                            {
                                // On macOS, open Finder to the directory
                                let _ = tauri::api::shell::open(&app.shell_scope(), &log_dir_str, None);
                            }

                            #[cfg(target_os = "linux")]
                            {
                                // On Linux, open file manager to the directory
                                let _ = tauri::api::shell::open(&app.shell_scope(), &log_dir_str, None);
                            }
                        } else {
                            error!("Could not determine log directory");
                        }
                    }
                    "open_panel" => {
                        let url = format!("{}/devices", config.base_url);
                        info!("Opening panel: {}", url);
                        let _ = tauri::api::shell::open(&app.shell_scope(), url, None);
                    }
                    "quit" => {
                        info!("Quit requested - initiating graceful shutdown");
                        let app_handle = app.app_handle();

                        // Trigger graceful shutdown on agent if available
                        if let Some(agent) = app_handle.try_state::<Arc<Agent>>() {
                            agent.shutdown();
                        }

                        // Give a moment for cleanup, then exit
                        std::thread::sleep(std::time::Duration::from_millis(500));
                        std::process::exit(0);
                    }
                    _ => {}
                },
                _ => {}
            }
        })
        .setup(|app| {
            let app_handle = app.app_handle();

            // Store for pending updates
            app.manage(Arc::new(RwLock::new(None::<UpdateInfo>)));

            // Initialize and start the agent
            tauri::async_runtime::spawn(async move {
                // Create agent
                let agent = match Agent::new().await {
                    Ok(agent) => Arc::new(agent),
                    Err(e) => {
                        error!("Failed to create agent: {}", e);
                        update_tray_status(
                            &app_handle,
                            &AgentState::Error(format!("Init failed: {}", e)),
                        );
                        return;
                    }
                };

                // Store agent in app state
                app_handle.manage(agent.clone());

                // Start status monitor (for tray updates)
                let agent_clone = agent.clone();
                let app_handle_clone = app_handle.clone();
                tauri::async_runtime::spawn(async move {
                    loop {
                        match agent_clone.check_status().await {
                            Ok(state) => {
                                update_tray_status(&app_handle_clone, &state);
                            }
                            Err(e) => {
                                error!("Status check error: {}", e);
                            }
                        }

                        tokio::time::sleep(std::time::Duration::from_secs(60)).await;
                    }
                });

                // Check for updates on startup
                let app_handle_update = app_handle.clone();
                tauri::async_runtime::spawn(async move {
                    // Wait a bit before checking for updates
                    tokio::time::sleep(std::time::Duration::from_secs(30)).await;

                    if let Ok(updater) = Updater::new() {
                        if let Ok(Some(update)) = updater.check_for_update().await {
                            info!("Update available on startup: {} -> {}", update.current_version, update.latest_version);

                            let _ = Notification::new(&app_handle_update.config().tauri.bundle.identifier)
                                .title("Update Available")
                                .body(&format!("Version {} is available", update.latest_version))
                                .show();

                            // Update menu
                            let tray = app_handle_update.tray_handle();
                            let _ = tray.get_item("check_update").set_title("Update Available!");

                            // Store update info
                            if let Some(update_state) = app_handle_update.try_state::<Arc<RwLock<Option<UpdateInfo>>>>() {
                                *update_state.write().await = Some(update);
                            }
                        }
                    }
                });

                // Start the main agent (this will block on enrollment or metrics loop)
                if let Err(e) = agent.start().await {
                    error!("Agent failed to start: {}", e);
                    update_tray_status(
                        &app_handle,
                        &AgentState::Error(format!("Start failed: {}", e)),
                    );
                }
            });

            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
