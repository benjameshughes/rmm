# {APP_NAME} - RMM Agent

A production-ready Tauri-based system tray agent for remote monitoring and management.

## Features

### System Information Collection
- Hostname, OS name/version
- CPU model and core count
- Total RAM (bytes and GB)
- Disk information (drives, sizes, free space)
- Hardware fingerprint for unique device identification

### Metrics Collection
- Real-time CPU metrics from Netdata
- Real-time RAM metrics from Netdata
- Automatic fallback when Netdata is unavailable
- Configurable collection interval (default: 60s)

### Agent Lifecycle
1. **Enrollment**: Automatic device enrollment with backend
2. **Approval Polling**: Waits for administrator approval
3. **Metrics Collection**: Continuous monitoring once approved
4. **Status Updates**: Regular status checks with backend

### System Tray Integration
- Real-time status display
- Quick access to:
  - Install/Repair agent
  - Open agent log
  - Open web panel
  - Quit application

## Architecture

```
src-tauri/src/
├── main.rs           # Entry point, tray setup, logging
├── config.rs         # Configuration management
├── agent.rs          # Main agent lifecycle and state
├── enrollment.rs     # Device enrollment and approval
├── metrics.rs        # Netdata metrics collection
├── sysinfo.rs        # System information gathering
└── storage.rs        # API key storage
```

## Requirements

### Development
- Rust toolchain (stable)
- Node.js (for Tauri CLI)
- Platform-specific dependencies:
  - **Windows**: Visual Studio Build Tools
  - **macOS**: Xcode Command Line Tools
  - **Linux**: webkit2gtk, libssl-dev, etc.

### Runtime
- Netdata (optional but recommended for full metrics)
  - Windows: Download from netdata.cloud
  - macOS: `brew install netdata`
  - Linux: `curl https://get.netdata.cloud/kickstart.sh | bash`

## Configuration

Default configuration in `config.rs`:

```rust
pub struct Config {
    base_url: "{BASE_URL}",              // Replaced at build time
    data_dir: "C:\\ProgramData\\RMM",    // Windows
    metrics_interval: 60,                 // seconds
    status_check_interval: 60,            // seconds
    enrollment_poll_interval: 30,         // seconds
    netdata_url: "http://127.0.0.1:19999"
}
```

Platform-specific data directories:
- **Windows**: `C:\ProgramData\RMM`
- **macOS**: `/Library/Application Support/RMM`
- **Linux**: `/var/lib/rmm`

## Development

```bash
# Install dependencies
npm install

# Run in development mode
npm run tauri:dev

# Or use cargo directly
cd src-tauri
cargo run
```

## Building

```bash
# Build for production
npm run tauri:build

# Or use cargo directly
cd src-tauri
cargo build --release
```

The build process will:
1. Replace `{BASE_URL}` and `{APP_NAME}` placeholders
2. Compile the Rust code
3. Create platform-specific installers in `src-tauri/target/release/bundle/`

## API Endpoints

The agent communicates with these Laravel backend endpoints:

### Enrollment
```http
POST /api/enroll
Content-Type: application/json

{
  "hostname": "DEVICE-NAME",
  "os": "Windows 11 Pro 23H2",
  "hardware_fingerprint": "sha256_hash",
  "cpu_model": "Intel Core i7-9700K",
  "cpu_cores": 8,
  "total_ram_bytes": 17179869184
}
```

### Status Check
```http
POST /api/check
Content-Type: application/json

{
  "hostname": "DEVICE-NAME",
  "hardware_fingerprint": "sha256_hash"
}

Response:
{
  "status": "approved|pending|revoked",
  "api_key": "key_if_approved"
}
```

### Metrics Submission
```http
POST /api/metrics
X-Agent-Key: api_key_from_enrollment
Content-Type: application/json

{
  "hostname": "DEVICE-NAME",
  "timestamp": "2025-12-05T10:00:00Z",
  "cpu": {
    "labels": ["time", "user", "system", "idle"],
    "data": [[1733392800, 10.0, 5.0, 85.0]]
  },
  "ram": {
    "labels": ["time", "used", "free"],
    "data": [[1733392800, 4294967296, 8589934592]]
  }
}
```

## Logging

Logs are written to:
- **Windows**: `C:\ProgramData\RMM\agent.log`
- **macOS**: `/Library/Application Support/RMM/agent.log`
- **Linux**: `/var/lib/rmm/agent.log`

Log rotation: Daily, managed by `tracing-appender`

Set log level via environment variable:
```bash
RUST_LOG=debug cargo run
```

## Error Handling

The agent includes comprehensive error handling:

- **Network errors**: Automatic retry with backoff
- **Netdata unavailable**: Graceful degradation (skips metrics)
- **Backend errors**: Logged with context
- **State errors**: Reflected in tray status

## Security

- API key stored securely in data directory
- HTTPS enforced via rustls (no native TLS)
- Hardware fingerprint for device identification
- No command execution (placeholder for future)

## Future Enhancements

Placeholder structure for PowerShell command execution:

```rust
// In agent.rs - TODO section
pub mod commands {
    pub struct CommandExecutor {
        // Command queue polling
        // PowerShell script execution
        // Output capture and reporting
        // Security validation
    }
}
```

## Testing

Run tests:
```bash
cd src-tauri
cargo test
```

Run with logging:
```bash
RUST_LOG=debug cargo test -- --nocapture
```

## Troubleshooting

### Agent won't start
1. Check logs in data directory
2. Verify network connectivity to backend
3. Ensure data directory is writable

### Metrics not appearing
1. Verify Netdata is running: `http://127.0.0.1:19999`
2. Check agent logs for errors
3. Ensure API key is valid

### Status shows "Pending"
1. Wait for administrator approval in web panel
2. Check backend logs
3. Verify device appears in `/devices` list

## License

Part of the {APP_NAME} RMM system.
