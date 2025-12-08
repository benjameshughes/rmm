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

# Ensure TLS 1.2 for web requests (PS5/PS7 compatible)
function Ensure-Tls12 {
    $sp = [System.Net.ServicePointManager]::SecurityProtocol
    if (-not ($sp.HasFlag([System.Net.SecurityProtocolType]::Tls12))) {
        [System.Net.ServicePointManager]::SecurityProtocol = $sp -bor [System.Net.SecurityProtocolType]::Tls12
    }
}

# Ensure the script runs elevated
function Require-Admin {
    $currentIdentity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($currentIdentity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

# If not admin, re-launch elevated and re-execute from URL
$ThisScriptUrl = "{BASE_URL}/agent/install.ps1"
if (-not (Require-Admin)) {
    try {
        Ensure-Tls12
        $elevatedCmd = "[System.Net.ServicePointManager]::SecurityProtocol=[System.Net.SecurityProtocolType]::Tls12; iwr -useb '$ThisScriptUrl' | iex"
        Start-Process PowerShell -Verb RunAs -ArgumentList @("-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", $elevatedCmd)
        exit
    } catch {
        Write-Host "Please run this script in an elevated PowerShell (Run as Administrator)." -ForegroundColor Yellow
        exit 1
    }
}

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
# STEP 0 — REMOVE EXISTING INSTALLATION (Clean Install)
# ===================================================================

Log "Checking for existing RMM installation..."

# Kill tray app if running
$trayProcessName = "benjh-rmm"
$runningTray = Get-Process -Name $trayProcessName -ErrorAction SilentlyContinue
if ($runningTray) {
    Log "Stopping running tray app..."
    Stop-Process -Name $trayProcessName -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
}

# Remove scheduled task
try {
    $existingTask = Get-ScheduledTask -TaskName "RMM-Metrics-Agent" -ErrorAction SilentlyContinue
    if ($existingTask) {
        Log "Removing existing scheduled task..."
        Unregister-ScheduledTask -TaskName "RMM-Metrics-Agent" -Confirm:$false -ErrorAction SilentlyContinue
    }
} catch { }

# Remove startup registry entry
try {
    $regPath = "HKLM:\Software\Microsoft\Windows\CurrentVersion\Run"
    $existingReg = Get-ItemProperty -Path $regPath -Name "RMM-Tray" -ErrorAction SilentlyContinue
    if ($existingReg) {
        Log "Removing startup registry entry..."
        Remove-ItemProperty -Path $regPath -Name "RMM-Tray" -ErrorAction SilentlyContinue
    }
} catch { }

# Remove old files (keep the directory)
$filesToRemove = @(
    "$AgentRoot\benjh-rmm.exe",
    "$AgentRoot\agent-metrics.ps1",
    "$AgentRoot\agent.key",
    "$AgentRoot\config.json"
)

foreach ($file in $filesToRemove) {
    if (Test-Path $file) {
        Log "Removing $file..."
        Remove-Item -Path $file -Force -ErrorAction SilentlyContinue
    }
}

# Clear old logs (optional - keep last log for debugging)
# Remove-Item -Path "$AgentRoot\*.log" -Force -ErrorAction SilentlyContinue

Log "Cleanup complete. Proceeding with fresh install..."


# ===================================================================
# STEP 1 — Install Netdata
# ===================================================================
function Test-NetdataInstalled { if (Get-Service -Name "netdata" -ErrorAction SilentlyContinue) { return $true } else { return $false } }
function Ensure-NetdataRunning {
    try { Set-Service -Name "netdata" -StartupType Automatic -ErrorAction SilentlyContinue } catch {}
    try {
        $svc = Get-Service -Name "netdata" -ErrorAction SilentlyContinue
        if ($svc -and $svc.Status -ne 'Running') { Start-Service -Name "netdata" -ErrorAction SilentlyContinue }
    } catch {}
}

Log "Installing/Checking Netdata..."
Ensure-Tls12

if (Test-NetdataInstalled) {
    Log "Netdata already installed. Ensuring service is running..."
    Ensure-NetdataRunning

    Start-Sleep -Seconds 2
    $apiOk = $false
    try { Invoke-WebRequest -Uri "http://127.0.0.1:19999/api/v1/info" -UseBasicParsing | Out-Null; $apiOk = $true } catch { $apiOk = $false }
    if (-not $apiOk) {
        Log "Netdata service running but API not reachable, attempting restart..."
        try { Restart-Service -Name "netdata" -Force -ErrorAction SilentlyContinue } catch {}
        Start-Sleep -Seconds 3
        try { Invoke-WebRequest -Uri "http://127.0.0.1:19999/api/v1/info" -UseBasicParsing | Out-Null; $apiOk = $true } catch { $apiOk = $false }
    }
    if ($apiOk) { Log "Netdata API reachable." } else { Log "WARNING: Netdata API not reachable. Continuing anyway." }
} else {
    try {
        function Resolve-NetdataMsiUrl {
            param([string]$PinnedTag = 'v2.8.2')

            $urls = @()
            try {
                $headers = @{ 'User-Agent' = 'rmm-installer' }
                $latest = Invoke-RestMethod -Headers $headers -Uri "https://api.github.com/repos/netdata/netdata/releases/latest"
                $tag = $latest.tag_name
                if ($tag) {
                    $urls += "https://github.com/netdata/netdata/releases/download/$tag/netdata-x64.msi"
                    $urls += "https://github.com/netdata/netdata/releases/download/$tag/netdata.msi"
                }
            } catch { }

            # Fallback to pinned known-good tag
            $urls += "https://github.com/netdata/netdata/releases/download/$PinnedTag/netdata-x64.msi"
            $urls += "https://github.com/netdata/netdata/releases/download/$PinnedTag/netdata.msi"

            return $urls
        }

        $tempInstaller = Join-Path $env:TEMP "netdata.msi"

        $downloaded = $false
        foreach ($u in (Resolve-NetdataMsiUrl)) {
            try {
                Log "Attempting Netdata download from: $u"
                Invoke-WebRequest -Uri $u -OutFile $tempInstaller -UseBasicParsing
                $downloaded = $true
                $netdataUrl = $u
                break
            } catch {
                Log "Download failed from: $u"
            }
        }

        if (-not $downloaded) { throw "Unable to download Netdata MSI from known URLs." }

        Start-Process "msiexec.exe" -ArgumentList "/i `"$tempInstaller`" /qn /norestart" -Wait
        Remove-Item $tempInstaller -Force -ErrorAction SilentlyContinue

        Ensure-NetdataRunning

        Start-Sleep -Seconds 3
        try { Invoke-WebRequest -Uri "http://127.0.0.1:19999/api/v1/info" -UseBasicParsing | Out-Null; Log "Netdata installed successfully from $netdataUrl." } catch { Log "WARNING: Netdata did not respond yet. Continuing anyway." }
    } catch {
        Log "WARNING: Netdata installation failed: $_. Continuing without Netdata."
    }
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
        Ensure-Tls12
        $resp = Invoke-RestMethod -Uri $CheckUrl -Method POST -Body (@{ hostname = $hostname } | ConvertTo-Json) -ContentType "application/json"

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

`$RmmBaseUrl  = "{BASE_URL}"
`$MetricsUrl  = "{BASE_URL}/api/metrics"
`$KeyFile     = "$KeyFile"
`$LogFile     = "$LogFile"

function Log {
    param([string]`$msg)
    `$timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    "`$timestamp  `$msg" | Out-File `$LogFile -Append
}

# Load API key
if (!(Test-Path `$KeyFile)) {
    Log "ERROR: No API key found. Exiting."
    exit
}

`$apiKey = (Get-Content `$KeyFile -Raw)

try {
    # Collect metrics from Netdata (raw JSON)
    try { `$cpu  = (Invoke-WebRequest -Uri "http://127.0.0.1:19999/api/v1/data?chart=system.cpu").Content } catch { `$cpu = `$null }
    try { `$ram  = (Invoke-WebRequest -Uri "http://127.0.0.1:19999/api/v1/data?chart=system.ram").Content } catch { `$ram = `$null }
    try { `$disk = (Invoke-WebRequest -Uri "http://127.0.0.1:19999/api/v1/charts?filter=disk").Content } catch { `$disk = `$null }

    `$payload = @{
        hostname = `$env:COMPUTERNAME
        cpu = `$cpu
        ram = `$ram
        disks = `$disk
        timestamp = (Get-Date).ToUniversalTime().ToString("o")
    } | ConvertTo-Json

    `$apiKey = `$apiKey.Trim()
    Invoke-RestMethod -Uri `$MetricsUrl -Method POST -Headers @{ "X-Agent-Key" = `$apiKey } -Body `$payload -ContentType "application/json"

    Log "Metrics sent."
}
catch {
    Log "ERROR sending metrics: `$_"
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
# STEP 6 — INSTALL TRAY APP (optional)
# ===================================================================

$TrayExePath = "$AgentRoot\benjh-rmm.exe"
$GitHubRepo = "benjameshughes/rmm"

Log "Checking for tray app from GitHub releases..."

try {
    # Query GitHub API for latest release
    $headers = @{ 'User-Agent' = 'rmm-installer' }
    $releaseUrl = "https://api.github.com/repos/$GitHubRepo/releases/latest"
    $release = Invoke-RestMethod -Uri $releaseUrl -Headers $headers -ErrorAction Stop

    # Find the .exe asset (regardless of exact name)
    $exeAsset = $release.assets | Where-Object { $_.name -like "*.exe" } | Select-Object -First 1

    if ($exeAsset) {
        $TrayExeUrl = $exeAsset.browser_download_url
        Log "Found tray app: $($exeAsset.name) from release $($release.tag_name)"

        # Stop running tray app if present (prevents file lock during update)
        $trayProcessName = "benjh-rmm"
        $runningTray = Get-Process -Name $trayProcessName -ErrorAction SilentlyContinue
        if ($runningTray) {
            Log "Stopping running tray app for update..."
            Stop-Process -Name $trayProcessName -Force -ErrorAction SilentlyContinue
            Start-Sleep -Seconds 2  # Give it time to fully exit
        }

        Log "Downloading tray app from $TrayExeUrl..."
        Invoke-WebRequest -Uri $TrayExeUrl -OutFile $TrayExePath -UseBasicParsing

        # Remove "downloaded from internet" flag (bypasses SmartScreen)
        Unblock-File -Path $TrayExePath -ErrorAction SilentlyContinue

        # Add Windows Defender exclusion for RMM folder
        try {
            Add-MpPreference -ExclusionPath $AgentRoot -ErrorAction SilentlyContinue
            Log "Added Defender exclusion for $AgentRoot"
        } catch {
            Log "WARNING: Could not add Defender exclusion (may need manual approval)"
        }

        # Register tray app to run at startup (current user)
        $regPath = "HKLM:\Software\Microsoft\Windows\CurrentVersion\Run"
        Set-ItemProperty -Path $regPath -Name "RMM-Tray" -Value "`"$TrayExePath`"" -ErrorAction SilentlyContinue
        Log "Registered tray app for startup"

        # Launch tray app now
        Start-Process -FilePath $TrayExePath -ErrorAction SilentlyContinue
        Log "Tray app launched"
    } else {
        Log "No .exe asset found in latest release. Skipping tray app."
    }
} catch {
    Log "Tray app not available from GitHub releases: $_. Skipping."
}


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
