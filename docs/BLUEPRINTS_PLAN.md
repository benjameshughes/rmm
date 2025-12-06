# Blueprints Remote Command Execution - Implementation Plan

## Overview

Add a "Blueprints" system to the RMM that allows:
1. Creating reusable script templates (Blueprints)
2. Deploying them to devices via Laravel jobs
3. Agent polls for pending commands, executes, reports back

## Flow

```
Admin creates Blueprint (template)
    ↓
Admin deploys to Device → DispatchBlueprintJob queued
    ↓
Job creates DeviceCommand (status: pending)
    ↓
Agent polls GET /api/commands/pending
    ↓
Agent marks POST /api/commands/{id}/start (status: running)
    ↓
Agent executes PowerShell/sh, captures output
    ↓
Agent reports POST /api/commands/{id}/complete (status: completed/failed/timeout)
```

---

## Phase 1: Database

### Migration: blueprints
```
- id
- name (string)
- description (text, nullable)
- script (text) - template with {{variable}} placeholders
- variables (json, nullable) - [{name, type, default, required}]
- shell (string, default: auto) - auto/powershell/bash/sh
- timeout (int, default: 300) - seconds
- is_active (bool, default: true)
- timestamps
```

### Migration: device_commands
```
- id
- device_id (FK → devices, cascade delete)
- blueprint_id (FK → blueprints, nullable, null on delete)
- name (string)
- script (text) - rendered script with variables substituted
- shell (string, default: auto)
- variables (json, nullable) - values used
- status (string, default: pending) - pending/running/completed/failed/timeout/cancelled
- exit_code (int, nullable)
- stdout (longText, nullable)
- stderr (longText, nullable)
- error_message (text, nullable)
- timeout (int, default: 300)
- queued_at (timestamp)
- started_at (timestamp, nullable)
- completed_at (timestamp, nullable)
- timestamps

Indexes: status, (device_id, status), (device_id, queued_at)
```

---

## Phase 2: Models

### Blueprint
- Fillable: name, description, script, variables, shell, timeout, is_active
- Casts: variables → array, timeout → int, is_active → bool
- Relationships: hasMany DeviceCommand
- Methods: `renderScript(array $values)` - replaces {{var}} placeholders

### DeviceCommand
- Constants: STATUS_PENDING, STATUS_RUNNING, STATUS_COMPLETED, STATUS_FAILED, STATUS_TIMEOUT, STATUS_CANCELLED
- Fillable: all columns
- Casts: variables → array, exit_code → int, timeout → int, queued_at/started_at/completed_at → datetime
- Relationships: belongsTo Device, belongsTo Blueprint
- Methods: `markAsRunning()`, `markAsCompleted($exitCode, $stdout, $stderr)`, `markAsTimeout()`, `markAsFailed($msg)`

### Device (update)
- Add: `commands()` hasMany DeviceCommand
- Add: `pendingCommands()` hasMany where status=pending

---

## Phase 3: Laravel Job

### DispatchBlueprintJob
```php
class DispatchBlueprintJob implements ShouldQueue
{
    public function __construct(
        public Blueprint $blueprint,
        public Device $device,
        public array $variables = []
    ) {}

    public function handle(): void
    {
        DeviceCommand::create([
            'device_id' => $this->device->id,
            'blueprint_id' => $this->blueprint->id,
            'name' => $this->blueprint->name,
            'script' => $this->blueprint->renderScript($this->variables),
            'shell' => $this->blueprint->shell,
            'variables' => $this->variables,
            'timeout' => $this->blueprint->timeout,
            'status' => DeviceCommand::STATUS_PENDING,
            'queued_at' => now(),
        ]);
    }
}
```

---

## Phase 4: API Endpoints

### GET /api/commands/pending
- Auth: X-Agent-Key header
- Returns: `{ commands: [{ id, name, script, shell, timeout, queued_at }] }`
- Updates device last_seen

### POST /api/commands/{id}/start
- Auth: X-Agent-Key header
- Validates command belongs to device and is pending
- Sets status=running, started_at=now

### POST /api/commands/{id}/complete
- Auth: X-Agent-Key header
- Body: `{ exit_code, stdout, stderr, timeout, error_message }`
- Sets status based on result, completed_at=now

### Rate Limiter
- `api.commands`: 60/min per API key

---

## Phase 5: Tauri Agent - commands.rs

### Structs
```rust
struct PendingCommand { id, name, script, shell, timeout, queued_at }
struct CommandResult { exit_code, stdout, stderr, timeout, error_message }
```

### CommandExecutor
- `fetch_pending(api_key)` → Vec<PendingCommand>
- `mark_started(api_key, command_id)`
- `execute(command)` → CommandResult (runs PowerShell/sh with timeout)
- `report_complete(api_key, command_id, result)`
- `start_command_loop(api_key)` - polls every 30s

### Shell Detection
- Windows: `powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command`
- Unix: `sh -c` or `bash -c`
- Auto: detect OS

### Config Addition
- `command_poll_interval: u64` (default: 30)

---

## Files to Create/Modify

### Create
- `database/migrations/..._create_blueprints_table.php`
- `database/migrations/..._create_device_commands_table.php`
- `app/Models/Blueprint.php`
- `app/Models/DeviceCommand.php`
- `database/factories/BlueprintFactory.php`
- `database/factories/DeviceCommandFactory.php`
- `app/Jobs/DispatchBlueprintJob.php`
- `app/Http/Controllers/Api/DeviceCommandController.php`
- `app/Http/Requests/CommandStartRequest.php`
- `app/Http/Requests/CommandCompleteRequest.php`
- `resources/tauri/tray-template/src-tauri/src/commands.rs`
- `tests/Feature/Api/CommandsTest.php`

### Modify
- `app/Models/Device.php` - add commands() relationship
- `routes/api.php` - add command routes
- `app/Providers/AppServiceProvider.php` - add rate limiter
- `resources/tauri/tray-template/src-tauri/src/config.rs` - add poll interval
- `resources/tauri/tray-template/src-tauri/src/main.rs` - add mod commands, spawn loop
- `resources/tauri/tray-template/src-tauri/src/agent.rs` - integrate command executor

---

## Implementation Order

1. **Database**: Migrations + run migrate
2. **Models**: Blueprint, DeviceCommand, update Device
3. **Factories**: For testing
4. **Job**: DispatchBlueprintJob
5. **API**: Controller, requests, routes, rate limiter
6. **Tests**: API endpoint tests
7. **Tauri**: commands.rs module
8. **Integration**: Wire into agent lifecycle

---

## Security Notes

- Stdout/stderr limited to 1MB in agent
- Timeout enforced via tokio::time::timeout
- All endpoints require device API key auth
- Rate limited to prevent abuse
- Script runs with agent's privileges (SYSTEM on Windows)

---

## Future Enhancements (Not in this phase)

- UI for Blueprint CRUD
- UI for command history/output viewing
- WebSocket for real-time output streaming
- Command cancellation
- Scheduled blueprint execution
