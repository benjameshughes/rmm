#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

mod agent;
mod config;
mod enrollment;
mod metrics;
mod storage;
mod sysinfo;

use agent::{Agent, AgentState};
use config::Config;
use std::sync::Arc;
use tauri::{
    CustomMenuItem, Manager, SystemTray, SystemTrayEvent, SystemTrayMenu, SystemTrayMenuItem,
};
use tracing::{error, info};
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt};

/// Initialize logging
fn init_logging(config: &Config) {
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

    let (non_blocking, _guard) = tracing_appender::non_blocking(file_appender);

    // Set up subscriber with file output
    tracing_subscriber::registry()
        .with(
            tracing_subscriber::EnvFilter::try_from_default_env()
                .unwrap_or_else(|_| "info,reqwest=warn,hyper=warn".into()),
        )
        .with(tracing_subscriber::fmt::layer().with_writer(non_blocking))
        .init();

    info!("Logging initialized to {:?}", config.log_file);
}

/// Build the system tray menu
fn build_tray_menu() -> SystemTrayMenu {
    SystemTrayMenu::new()
        .add_item(CustomMenuItem::new("status", "Status: Initializing...").disabled())
        .add_native_item(SystemTrayMenuItem::Separator)
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

    // Initialize logging
    init_logging(&config);

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
                    "install" => {
                        let url = format!("{}/agent/install.ps1", config.base_url);
                        info!("Opening install script: {}", url);
                        let _ = tauri::api::shell::open(&app.shell_scope(), url, None);
                    }
                    "open_log" => {
                        info!("Opening log file: {:?}", config.log_file);
                        let log_path = config.log_file.to_string_lossy().to_string();
                        let _ = tauri::api::shell::open(&app.shell_scope(), log_path, None);
                    }
                    "open_panel" => {
                        let url = format!("{}/devices", config.base_url);
                        info!("Opening panel: {}", url);
                        let _ = tauri::api::shell::open(&app.shell_scope(), url, None);
                    }
                    "quit" => {
                        info!("Quit requested");
                        std::process::exit(0);
                    }
                    _ => {}
                },
                _ => {}
            }
        })
        .setup(|app| {
            let app_handle = app.app_handle();

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
