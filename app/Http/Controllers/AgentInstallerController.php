<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class AgentInstallerController extends Controller
{
    public function download(): Response
    {
        $base = url('/');

        $script = <<<'PS1'
# ===================================================================
#  RMM Unified Agent Installer
#  - Installs Netdata
#  - Enrolls device with cloud RMM
#  - Polls for approval
#  - Stores API key securely
#  - Starts Metrics Agent
#  - Registers Scheduled Tasks
# ===================================================================

$ErrorActionPreference = "Stop"

# -------------------------------
# CONFIG
# -------------------------------
$RmmBaseUrl   = "{BASE_URL}"
$EnrollUrl    = "$RmmBaseUrl/api/enroll"
$CheckUrl     = "$RmmBaseUrl/api/check"
$MetricsUrl   = "$RmmBaseUrl/api/metrics"

$AgentRoot    = "C:\ProgramData\RMM"
$KeyFile      = "$AgentRoot\agent.key"
$LogFile      = "$AgentRoot\agent.log"
$AgentScript  = "$AgentRoot\agent-metrics.ps1"


# -------------------------------
# Ensure folders exist
# -------------------------------
if (!(Test-Path $AgentRoot)) {
    New-Item -Path $AgentRoot -ItemType Directory | Out-Null
}

# Logging helper
function Log {
    param([string]$msg)
    $timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    "$timestamp  $msg" | Out-File $LogFile -Append
    Write-Host $msg
}

Log "===== RMM Agent Install Starting ====="


# ===================================================================
# STEP 1 — Install Netdata
# ===================================================================
Log "Installing Netdata..."

$netdataUrl = "https://github.com/netdata/netdata/releases/latest/download/netdata.msi"
$tempInstaller = "$env:TEMP\netdata.msi"

Invoke-WebRequest -Uri $netdataUrl -OutFile $tempInstaller -UseBasicParsing
Start-Process "msiexec.exe" -ArgumentList "/i `"$tempInstaller`" /qn /norestart" -Wait
Remove-Item $tempInstaller -Force

Start-Service -Name "netdata" -ErrorAction SilentlyContinue
Set-Service -Name "netdata" -StartupType Automatic

Start-Sleep -Seconds 3

try {
    Invoke-WebRequest -Uri "http://127.0.0.1:19999/api/v1/info" -TimeoutSec 5 -UseBasicParsing | Out-Null
    Log "Netdata installed successfully."
} catch {
    Log "WARNING: Netdata did not respond yet. Continuing anyway."
}


# ===================================================================
# STEP 2 — ENROLL DEVICE (no API key yet)
# ===================================================================
$hostname = $env:COMPUTERNAME
$body = @{ hostname = $hostname } | ConvertTo-Json

Log "Sending enrollment request for $hostname..."

Invoke-RestMethod -Uri $EnrollUrl -Method POST -Body $body -ContentType "application/json" | Out-Null

Log "Enrollment sent. Waiting for approval..."


# ===================================================================
# STEP 3 — Poll for approval / receive API key
# ===================================================================
while ($true) {
    try {
        $resp = Invoke-RestMethod -Uri $CheckUrl -Method POST `
            -Body (@{ hostname = $hostname } | ConvertTo-Json) -ContentType "application/json"

        if ($resp.status -eq "approved") {
            $resp.api_key | Out-File $KeyFile -Encoding ascii -Force
            Log "Device approved. API key saved."
            break
        }

        Log "Waiting for approval..."
    } catch {
        Log "Error polling approval: $_"
    }

    Start-Sleep -Seconds 10
}

Log "Enrollment complete."


# ===================================================================
# STEP 4 — CREATE METRICS AGENT SCRIPT
# ===================================================================

$agentContent = @"
# --------------------------------------------------------
# RMM Metrics Agent (auto-generated)
# Runs periodically to send metrics to the cloud panel.
# --------------------------------------------------------

\$RmmBaseUrl  = "{BASE_URL}"
\$MetricsUrl  = "{BASE_URL}/api/metrics"
\$KeyFile     = "$KeyFile"
\$LogFile     = "$LogFile"

function Log {
    param([string]\$msg)
    \$timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    "\$timestamp  \$msg" | Out-File \$LogFile -Append
}

# Load API key
if (!(Test-Path \$KeyFile)) {
    Log "ERROR: No API key found. Exiting."
    exit
}

\$apiKey = (Get-Content \$KeyFile -Raw)

try {
    # Collect metrics from Netdata (raw JSON)
    \$cpu  = (Invoke-WebRequest -Uri "http://127.0.0.1:19999/api/v1/data?chart=system.cpu" -UseBasicParsing).Content
    \$ram  = (Invoke-WebRequest -Uri "http://127.0.0.1:19999/api/v1/data?chart=system.ram" -UseBasicParsing).Content
    \$disk = (Invoke-WebRequest -Uri "http://127.0.0.1:19999/api/v1/charts?filter=disk" -UseBasicParsing).Content

    \$payload = @{
        hostname = \$env:COMPUTERNAME
        cpu = \$cpu
        ram = \$ram
        disks = \$disk
        timestamp = (Get-Date).ToUniversalTime().ToString("o")
    } | ConvertTo-Json

    Invoke-RestMethod -Uri \$MetricsUrl -Method POST `
        -Headers @{ "X-Agent-Key" = \$apiKey } `
        -Body \$payload -ContentType "application/json"

    Log "Metrics sent."
}
catch {
    Log "ERROR sending metrics: \$_"
}
"@

Set-Content -Path $AgentScript -Value $agentContent -Force


Log "Metrics agent script created."


# ===================================================================
# STEP 5 — REGISTER SCHEDULED TASK FOR METRICS LOOP (every 1 minute)
# ===================================================================

Log "Registering Scheduled Task: RMM Metrics Sender..."

try { Unregister-ScheduledTask -TaskName "RMM-Metrics-Agent" -Confirm:$false -ErrorAction Stop } catch { }

$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$AgentScript`""

$repeatInterval = New-TimeSpan -Minutes 1
$repeatDuration = New-TimeSpan -Days 3650

$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval $repeatInterval `
    -RepetitionDuration $repeatDuration

$startupTrigger = New-ScheduledTaskTrigger -AtStartup

$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName "RMM-Metrics-Agent" `
    -Action $action `
    -Trigger @($trigger, $startupTrigger) `
    -Principal $principal `
    -Settings $settings `
    -Force | Out-Null

try { Start-ScheduledTask -TaskName "RMM-Metrics-Agent" } catch { }

Log "Scheduled task created."


# ===================================================================
# DONE
# ===================================================================

Log "🎉 RMM Agent Installation Complete!"
Write-Host "Installation finished successfully!" -ForegroundColor Green
PS1;

        $content = str_replace('{BASE_URL}', $base, $script);

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="agent-install.ps1"',
        ]);
    }
}

