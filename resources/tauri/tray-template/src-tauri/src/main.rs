#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

mod agent;
mod config;
mod enrollment;
mod metrics;
mod runtime_config;
mod storage;
mod sysinfo;
mod updater;

use agent::{Agent, AgentState};
use config::Config;
use runtime_config::RuntimeConfig;
use std::path::PathBuf;
use std::sync::Arc;
use tauri::{
    image::Image,
    menu::{MenuBuilder, MenuItemBuilder, PredefinedMenuItem},
    tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent},
    Manager,
};
use tauri_plugin_shell::ShellExt;
use tokio::sync::RwLock;
use tracing::{error, info};
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
fn build_tray_menu(app: &tauri::AppHandle) -> Result<tauri::menu::Menu<tauri::Wry>, tauri::Error> {
    let status_label = MenuItemBuilder::with_id("status_label", "Status: Initializing...")
        .enabled(false)
        .build(app)?;
    let version = MenuItemBuilder::with_id("version", format!("v{}", env!("CARGO_PKG_VERSION")))
        .enabled(false)
        .build(app)?;
    let separator1 = PredefinedMenuItem::separator(app)?;
    let open_status = MenuItemBuilder::with_id("open_status", "Status & Info").build(app)?;
    let open_settings = MenuItemBuilder::with_id("open_settings", "Settings").build(app)?;
    let open_logs = MenuItemBuilder::with_id("open_logs", "View Logs").build(app)?;
    let open_updates = MenuItemBuilder::with_id("open_updates", "Updates").build(app)?;
    let separator2 = PredefinedMenuItem::separator(app)?;
    let open_dashboard = MenuItemBuilder::with_id("open_dashboard", "Open Web Dashboard").build(app)?;
    let separator3 = PredefinedMenuItem::separator(app)?;
    let quit = MenuItemBuilder::with_id("quit", "Quit").build(app)?;

    MenuBuilder::new(app)
        .item(&status_label)
        .item(&version)
        .item(&separator1)
        .item(&open_status)
        .item(&open_settings)
        .item(&open_logs)
        .item(&open_updates)
        .item(&separator2)
        .item(&open_dashboard)
        .item(&separator3)
        .item(&quit)
        .build()
}

/// Show a window by label
fn show_window(app: &tauri::AppHandle, label: &str) {
    if let Some(window) = app.get_webview_window(label) {
        let _ = window.show();
        let _ = window.set_focus();
    }
}

/// Update the tray status label
fn update_tray_status(app_handle: &tauri::AppHandle, state: &AgentState) {
    // In Tauri v2, we need to rebuild the menu to update text
    // This is a limitation of the current tray API
    // For now, we'll skip dynamic menu updates
    // You could rebuild the entire menu if needed, but that's more expensive
    // Alternative: Use notifications or window status instead
    let _ = (app_handle, state); // Suppress unused warnings
}

/// Update state for tracking updates
#[derive(Debug, Clone)]
pub struct UpdateState {
    pub available: Option<UpdateInfo>,
    pub downloaded_path: Option<PathBuf>,
}

impl Default for UpdateState {
    fn default() -> Self {
        Self {
            available: None,
            downloaded_path: None,
        }
    }
}

/// Status information for the settings panel
#[derive(Debug, Clone, serde::Serialize)]
pub struct StatusInfo {
    pub connection_status: String,
    pub server_url: String,
    pub agent_version: String,
    pub hostname: String,
    pub os_name: String,
    pub os_version: String,
    pub cpu_model: String,
    pub cpu_cores: usize,
    pub total_ram_gb: f64,
    pub disks: Vec<crate::sysinfo::DiskInfo>,
    pub network_interfaces: Vec<crate::sysinfo::NetworkInterface>,
    pub netdata_available: bool,
    pub last_metrics_submission: Option<String>,
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

/// Check if Netdata API is reachable
async fn check_netdata_available(url: &str) -> bool {
    let client = match reqwest::Client::builder()
        .timeout(std::time::Duration::from_secs(2))
        .build()
    {
        Ok(c) => c,
        Err(_) => return false,
    };

    match client.get(format!("{}/api/v1/info", url)).send().await {
        Ok(resp) => resp.status().is_success(),
        Err(_) => false,
    }
}

#[tauri::command]
async fn get_status_info(
    app_handle: tauri::AppHandle,
    runtime_config: tauri::State<'_, Arc<RwLock<RuntimeConfig>>>,
) -> Result<StatusInfo, String> {
    let config_lock = runtime_config.read().await;
    let config = Config::with_runtime_config(&*config_lock);

    // Check Netdata availability
    let netdata_available = check_netdata_available(&config.netdata_url).await;

    // Try to get agent - it might not be ready yet
    if let Some(agent) = app_handle.try_state::<Arc<Agent>>() {
        let state = agent.get_state().await;
        let system_info = agent.system_info();

        Ok(StatusInfo {
            connection_status: state.as_display(),
            server_url: config.base_url.clone(),
            agent_version: env!("CARGO_PKG_VERSION").to_string(),
            hostname: system_info.hostname.clone(),
            os_name: system_info.os_name.clone(),
            os_version: system_info.os_version.clone(),
            cpu_model: system_info.cpu_model.clone(),
            cpu_cores: system_info.cpu_cores,
            total_ram_gb: system_info.total_ram_gb,
            disks: system_info.disks.clone(),
            network_interfaces: system_info.network_interfaces.clone(),
            netdata_available,
            last_metrics_submission: None,
        })
    } else {
        // Agent not ready yet - return placeholder
        Ok(StatusInfo {
            connection_status: "Initializing...".to_string(),
            server_url: config.base_url.clone(),
            agent_version: env!("CARGO_PKG_VERSION").to_string(),
            hostname: "Loading...".to_string(),
            os_name: "Loading...".to_string(),
            os_version: "".to_string(),
            cpu_model: "Loading...".to_string(),
            cpu_cores: 0,
            total_ram_gb: 0.0,
            disks: vec![],
            network_interfaces: vec![],
            netdata_available,
            last_metrics_submission: None,
        })
    }
}

#[tauri::command]
async fn get_server_url(
    runtime_config: tauri::State<'_, Arc<RwLock<RuntimeConfig>>>,
) -> Result<String, String> {
    let config_lock = runtime_config.read().await;
    let config = Config::with_runtime_config(&*config_lock);
    Ok(config.base_url)
}

#[tauri::command]
async fn set_server_url(
    url: String,
    runtime_config: tauri::State<'_, Arc<RwLock<RuntimeConfig>>>,
) -> Result<(), String> {
    let mut config_lock = runtime_config.write().await;
    config_lock.server_url = Some(url);
    config_lock.save().map_err(|e| e.to_string())?;
    Ok(())
}

#[tauri::command]
fn get_version() -> String {
    env!("CARGO_PKG_VERSION").to_string()
}

#[tauri::command]
async fn get_log_contents(lines: Option<usize>) -> Result<String, String> {
    let config = Config::default();
    let log_path = config.log_file;

    // Read as bytes and convert with lossy UTF-8 to handle any encoding issues
    match tokio::fs::read(&log_path).await {
        Ok(bytes) => {
            let content = String::from_utf8_lossy(&bytes);
            let lines_vec: Vec<&str> = content.lines().collect();
            let take_lines = lines.unwrap_or(200);
            let start = if lines_vec.len() > take_lines { lines_vec.len() - take_lines } else { 0 };
            Ok(lines_vec[start..].join("\n"))
        }
        Err(e) => Err(format!("Failed to read logs: {}", e))
    }
}

#[tauri::command]
async fn check_update_available() -> Result<Option<UpdateInfo>, String> {
    match Updater::new() {
        Ok(updater) => updater.check_for_update().await.map_err(|e| e.to_string()),
        Err(e) => Err(e.to_string())
    }
}

#[tauri::command]
async fn trigger_update_download(
    update_state: tauri::State<'_, Arc<RwLock<UpdateState>>>
) -> Result<String, String> {
    let state = update_state.read().await;
    if let Some(ref update) = state.available {
        match Updater::new() {
            Ok(updater) => {
                let path = updater.download_update(update).await.map_err(|e| e.to_string())?;
                Ok(path.to_string_lossy().to_string())
            }
            Err(e) => Err(e.to_string())
        }
    } else {
        Err("No update available".to_string())
    }
}

#[tauri::command]
fn get_config_values() -> Result<serde_json::Value, String> {
    let config = Config::default();
    Ok(serde_json::json!({
        "server_url": config.base_url,
        "netdata_url": config.netdata_url,
        "metrics_interval": config.metrics_interval,
        "log_file": config.log_file.to_string_lossy()
    }))
}

fn main() {
    // Initialize configuration
    let config = Config::default();

    // Initialize logging - keep guard alive for application lifetime
    let _log_guard = init_logging(&config);

    info!("=== RMM Agent Starting ===");
    info!("Version: {}", env!("CARGO_PKG_VERSION"));
    info!("Base URL: {}", config.base_url);

    tauri::Builder::default()
        .plugin(tauri_plugin_shell::init())
        .invoke_handler(tauri::generate_handler![
            get_agent_state,
            get_system_info,
            get_status_info,
            get_server_url,
            set_server_url,
            get_version,
            get_log_contents,
            check_update_available,
            trigger_update_download,
            get_config_values
        ])
        .on_window_event(|window, event| {
            if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                window.hide().unwrap();
                api.prevent_close();
            }
        })
        .setup(|app| {
            let app_handle = app.app_handle();

            // Build and setup system tray
            let menu = build_tray_menu(&app_handle)?;
            let icon_bytes = include_bytes!("../icons/icon.png");
            let icon = Image::from_bytes(icon_bytes)?;

            let tray = TrayIconBuilder::with_id("main-tray")
                .icon(icon)
                .menu(&menu)
                .show_menu_on_left_click(true)
                .on_menu_event(move |app, event| {
                    let config = Config::default();
                    match event.id().as_ref() {
                        "open_status" => {
                            info!("Opening status window");
                            show_window(&app, "status");
                        }
                        "open_settings" => {
                            info!("Opening settings window");
                            show_window(&app, "settings");
                        }
                        "open_logs" => {
                            info!("Opening logs window");
                            show_window(&app, "logs");
                        }
                        "open_updates" => {
                            info!("Opening updates window");
                            show_window(&app, "updates");
                        }
                        "open_dashboard" => {
                            let url = format!("{}/devices", config.base_url);
                            info!("Opening dashboard: {}", url);
                            let _ = app.shell().open(&url, None);
                        }
                        "quit" => {
                            info!("Quit requested - shutting down");

                            // Trigger graceful shutdown on agent if available
                            if let Some(agent) = app.try_state::<Arc<Agent>>() {
                                agent.shutdown();
                            }

                            // Give a moment for cleanup, then exit
                            std::thread::sleep(std::time::Duration::from_millis(300));
                            app.exit(0);
                        }
                        _ => {}
                    }
                })
                .on_tray_icon_event(|_tray, event| {
                    if let TrayIconEvent::Click { button: MouseButton::Left, button_state: MouseButtonState::Up, .. } = event {
                        // Menu shows on left click via config
                    }
                })
                .build(app)?;

            // Load runtime configuration
            let runtime_config = RuntimeConfig::load().unwrap_or_default();
            app.manage(Arc::new(RwLock::new(runtime_config)));

            // Store for pending updates
            app.manage(Arc::new(RwLock::new(UpdateState::default())));

            // Initialize and start the agent
            let app_handle_clone = app_handle.clone();
            tauri::async_runtime::spawn(async move {
                // Create agent
                let agent = match Agent::new().await {
                    Ok(agent) => Arc::new(agent),
                    Err(e) => {
                        error!("Failed to create agent: {}", e);
                        update_tray_status(
                            &app_handle_clone,
                            &AgentState::Error(format!("Init failed: {}", e)),
                        );
                        return;
                    }
                };

                // Store agent in app state
                app_handle_clone.manage(agent.clone());

                // Start status monitor (for tray updates)
                let agent_clone = agent.clone();
                let app_handle_status = app_handle_clone.clone();
                tauri::async_runtime::spawn(async move {
                    loop {
                        match agent_clone.check_status().await {
                            Ok(state) => {
                                update_tray_status(&app_handle_status, &state);
                            }
                            Err(e) => {
                                error!("Status check error: {}", e);
                            }
                        }

                        tokio::time::sleep(std::time::Duration::from_secs(60)).await;
                    }
                });

                // Check for updates on startup
                let app_handle_update = app_handle_clone.clone();
                tauri::async_runtime::spawn(async move {
                    // Wait a bit before checking for updates
                    tokio::time::sleep(std::time::Duration::from_secs(30)).await;

                    if let Ok(updater) = Updater::new() {
                        if let Ok(Some(update)) = updater.check_for_update().await {
                            info!("Update available on startup: {} -> {}", update.current_version, update.latest_version);

                            // Note: Notification API has changed in v2, would need tauri-plugin-notification
                            // For now, we'll just log it
                            // TODO: Add tauri-plugin-notification to show update notifications

                            // Store update info
                            if let Some(update_state) = app_handle_update.try_state::<Arc<RwLock<UpdateState>>>() {
                                update_state.write().await.available = Some(update);
                            }
                        }
                    }
                });

                // Start the main agent (non-blocking)
                if let Err(e) = agent.clone().start(app_handle_clone.clone()).await {
                    error!("Agent failed to start: {}", e);
                    update_tray_status(
                        &app_handle_clone,
                        &AgentState::Error(format!("Start failed: {}", e)),
                    );
                }
            });

            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
