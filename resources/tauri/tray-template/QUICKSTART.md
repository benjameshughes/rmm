# Quick Start Guide

## Prerequisites

### Development Environment
```bash
# Install Rust
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh

# Install Node.js (optional, for Tauri CLI)
# Download from nodejs.org or use package manager

# Platform-specific dependencies
# Windows: Install Visual Studio Build Tools
# macOS: xcode-select --install
# Linux: sudo apt install libwebkit2gtk-4.0-dev build-essential libssl-dev
```

### Runtime (Optional)
```bash
# Install Netdata for full metrics support
# Windows: Download from https://netdata.cloud
# macOS: brew install netdata
# Linux: curl https://get.netdata.cloud/kickstart.sh | bash
```

## Development

```bash
# Clone or navigate to project
cd /path/to/tray-template

# Install npm dependencies
npm install

# Run in development mode
cd src-tauri
cargo run

# Or use Tauri CLI
npm run tauri:dev
```

## Building

```bash
# Build release version
cd src-tauri
cargo build --release

# Or use Tauri CLI to build with installer
npm run tauri:build
```

The executable will be in:
- `src-tauri/target/release/rmm-tray` (or .exe on Windows)
- Installers in `src-tauri/target/release/bundle/`

## Configuration

Before building for production, update these placeholders:

### 1. Base URL
In `src/config.rs`, replace `{BASE_URL}` with your actual backend URL:
```rust
base_url: "https://your-rmm-panel.com".to_string(),
```

### 2. App Name
Replace `{APP_NAME}` in:
- `Cargo.toml` (description)
- `tauri.conf.json` (productName, window title)
- `src/index.html` (title, content)

### 3. Bundle Identifier
In `tauri.conf.json`, update:
```json
"identifier": "com.yourcompany.rmmtray"
```

### 4. Icons
Replace placeholder icons in `src-tauri/icons/`:
- Create proper branded icons
- Generate multiple sizes for Windows ICO
- See `icons/README.md` for details

## Testing

```bash
cd src-tauri

# Run all tests
cargo test

# Run with output
cargo test -- --nocapture

# Run specific test
cargo test test_gather_system_info

# Check code compiles
cargo check
```

## Logging

Logs are written to:
- Windows: `C:\ProgramData\RMM\agent.log`
- macOS: `/Library/Application Support/RMM/agent.log`
- Linux: `/var/lib/rmm/agent.log`

Set log level:
```bash
# Debug level
RUST_LOG=debug cargo run

# Trace level (very verbose)
RUST_LOG=trace cargo run

# Specific module
RUST_LOG=rmm_tray::metrics=debug cargo run
```

## Deployment

### Windows
```bash
# Build MSI installer
npm run tauri:build

# Output: src-tauri/target/release/bundle/msi/
```

### macOS
```bash
# Build DMG and app bundle
npm run tauri:build

# Output: src-tauri/target/release/bundle/dmg/
#         src-tauri/target/release/bundle/macos/
```

### Linux
```bash
# Build DEB and AppImage
npm run tauri:build

# Output: src-tauri/target/release/bundle/deb/
#         src-tauri/target/release/bundle/appimage/
```

## Usage Flow

1. **First Run**: Agent starts, detects no API key
2. **Enrollment**: Automatically enrolls device with backend
3. **Waiting**: Shows "Pending Approval" in tray
4. **Approval**: Admin approves device in web panel
5. **Active**: Agent receives API key, starts metrics collection
6. **Running**: Metrics sent every 60s, status checked every 60s

## Troubleshooting

### Compilation Errors

**Icon errors**: Ensure `icon.png` exists in `src-tauri/icons/`

**Dependency errors**: Update dependencies
```bash
cd src-tauri
cargo update
```

**Build script errors**: Ensure `build.rs` exists and `tauri-build` is in `[build-dependencies]`

### Runtime Errors

**Can't create data directory**: Run with admin/sudo on first launch

**Netdata not available**: Install Netdata or agent will skip metrics

**Connection refused**: Check backend URL is correct and accessible

**API key not saved**: Check data directory permissions

## Environment Variables

```bash
# Backend URL (if not using placeholder replacement)
RMM_BASE_URL=https://your-panel.com

# Log level
RUST_LOG=info

# Data directory (override default)
RMM_DATA_DIR=/custom/path

# Metrics interval (seconds)
RMM_METRICS_INTERVAL=60

# Status check interval (seconds)
RMM_STATUS_INTERVAL=60
```

## Development Tips

### Hot Reload
Use `cargo watch` for auto-rebuild:
```bash
cargo install cargo-watch
cargo watch -x run
```

### Code Formatting
```bash
cargo fmt
```

### Linting
```bash
cargo clippy
```

### Documentation
```bash
cargo doc --open
```

## Common Customizations

### Change Intervals
Edit `src/config.rs`:
```rust
metrics_interval: 30,        // Collect every 30s
status_check_interval: 120,  // Check every 2 minutes
```

### Add Tray Menu Items
Edit `src/main.rs` in `build_tray_menu()`:
```rust
.add_item(CustomMenuItem::new("custom", "Custom Action"))
```

And handle in `on_system_tray_event`:
```rust
"custom" => {
    // Your code here
}
```

### Change Log Format
Edit `src/main.rs` in `init_logging()`:
```rust
.with(tracing_subscriber::fmt::layer()
    .with_target(false)  // Hide module names
    .compact()           // Compact format
)
```

## Getting Help

1. Check logs in data directory
2. Run with `RUST_LOG=debug` for verbose output
3. Review `README.md` for architecture details
4. See `IMPLEMENTATION.md` for technical details
5. Check Tauri docs: https://tauri.app/v1/guides/
6. Check sysinfo docs: https://docs.rs/sysinfo/

## License

Part of the {APP_NAME} RMM system.
