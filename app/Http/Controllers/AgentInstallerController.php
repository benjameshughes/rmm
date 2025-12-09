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
#  RMM Agent Installer
#  - Installs Netdata (metrics collector)
#  - Installs RMM Agent as Windows Service (handles enrollment & metrics)
# ===================================================================

$ErrorActionPreference = "Stop"

# Ensure TLS 1.2 for web requests
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

# If not admin, re-launch elevated
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
$AgentRoot = "C:\ProgramData\RMM"
$LogFile   = "$AgentRoot\agent.log"

# Ensure folders exist
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
# STEP 1 — CLEANUP EXISTING INSTALLATION
# ===================================================================

Log "Checking for existing RMM installation..."

# Stop and remove existing RMM service if present
$serviceName = "RMMAgent"
try {
    $existingService = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    if ($existingService) {
        Log "Stopping existing RMM service..."
        Stop-Service -Name $serviceName -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2

        Log "Removing existing RMM service..."
        sc.exe delete $serviceName | Out-Null
        Start-Sleep -Seconds 1
    }
} catch {
    Log "No existing service or removal failed: $_"
}

# Kill any running agent process
$agentProcessName = "rmm-agent"
$runningAgent = Get-Process -Name $agentProcessName -ErrorAction SilentlyContinue
if ($runningAgent) {
    Log "Stopping running agent process..."
    Stop-Process -Name $agentProcessName -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
}

# Kill legacy tray app if running
$trayProcessName = "benjh-rmm"
$runningTray = Get-Process -Name $trayProcessName -ErrorAction SilentlyContinue
if ($runningTray) {
    Log "Stopping running tray app..."
    Stop-Process -Name $trayProcessName -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
}

# Uninstall existing MSI if present
try {
    $installedProduct = Get-WmiObject -Class Win32_Product | Where-Object { $_.Name -like "*benjh-rmm*" -or $_.Name -like "*RMM*" } | Select-Object -First 1
    if ($installedProduct) {
        Log "Uninstalling existing MSI: $($installedProduct.Name)..."
        $installedProduct.Uninstall() | Out-Null
        Start-Sleep -Seconds 2
        Log "MSI uninstalled"
    }
} catch {
    Log "No existing MSI found or uninstall failed: $_"
}

# Remove legacy scheduled task (from older PS1-based metrics)
try {
    $existingTask = Get-ScheduledTask -TaskName "RMM-Metrics-Agent" -ErrorAction SilentlyContinue
    if ($existingTask) {
        Log "Removing legacy scheduled task..."
        Unregister-ScheduledTask -TaskName "RMM-Metrics-Agent" -Confirm:$false -ErrorAction SilentlyContinue
    }
} catch { }

# Remove legacy startup registry entry
try {
    $regPath = "HKLM:\Software\Microsoft\Windows\CurrentVersion\Run"
    $existingReg = Get-ItemProperty -Path $regPath -Name "RMM-Tray" -ErrorAction SilentlyContinue
    if ($existingReg) {
        Log "Removing startup registry entry..."
        Remove-ItemProperty -Path $regPath -Name "RMM-Tray" -ErrorAction SilentlyContinue
    }
} catch { }

# Remove old standalone files (keep agent.key for re-enrollment preservation)
$filesToRemove = @(
    "$AgentRoot\benjh-rmm.exe",
    "$AgentRoot\agent-metrics.ps1"
)

foreach ($file in $filesToRemove) {
    if (Test-Path $file) {
        Log "Removing legacy file: $file"
        Remove-Item -Path $file -Force -ErrorAction SilentlyContinue
    }
}

Log "Cleanup complete."


# ===================================================================
# STEP 1.5 — CLEANUP EXISTING NETDATA
# ===================================================================

Log "Checking for existing Netdata installation..."

# Stop Netdata service
try {
    $netdataSvc = Get-Service -Name "netdata" -ErrorAction SilentlyContinue
    if ($netdataSvc) {
        Log "Stopping Netdata service..."
        Stop-Service -Name "netdata" -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2
    }
} catch { }

# Uninstall Netdata MSI
try {
    $netdataProduct = Get-WmiObject -Class Win32_Product | Where-Object { $_.Name -like "*Netdata*" } | Select-Object -First 1
    if ($netdataProduct) {
        Log "Uninstalling Netdata: $($netdataProduct.Name)..."
        $netdataProduct.Uninstall() | Out-Null
        Start-Sleep -Seconds 3
        Log "Netdata MSI uninstalled"
    }
} catch {
    Log "No Netdata MSI found or uninstall failed: $_"
}

# Remove Netdata directories
$netdataDirs = @(
    "$env:ProgramFiles\Netdata",
    "$env:ProgramData\Netdata",
    "C:\Program Files\Netdata",
    "C:\ProgramData\Netdata"
)

foreach ($dir in $netdataDirs) {
    if (Test-Path $dir) {
        Log "Removing Netdata directory: $dir"
        Remove-Item -Path $dir -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# Remove Netdata service registration (if MSI uninstall didn't)
try {
    $svc = Get-Service -Name "netdata" -ErrorAction SilentlyContinue
    if ($svc) {
        Log "Removing orphaned Netdata service..."
        sc.exe delete netdata | Out-Null
    }
} catch { }

Log "Netdata cleanup complete."


# ===================================================================
# STEP 2 — INSTALL NETDATA
# ===================================================================

function Test-NetdataInstalled {
    if (Get-Service -Name "netdata" -ErrorAction SilentlyContinue) { return $true }
    return $false
}

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
        try { Invoke-WebRequest -Uri "http://127.0.0.1:19999/api/v1/info" -UseBasicParsing | Out-Null; Log "Netdata installed successfully." } catch { Log "WARNING: Netdata did not respond yet. Continuing anyway." }
    } catch {
        Log "WARNING: Netdata installation failed: $_. Continuing without Netdata."
    }
}


# ===================================================================
# STEP 3 — INSTALL RMM AGENT AS WINDOWS SERVICE
# ===================================================================

$GitHubRepo = "benjameshughes/rmm"
$ServerUrl = "{BASE_URL}"
$TempMsiPath = Join-Path $env:TEMP "rmm-agent.msi"

Log "Downloading RMM Agent from GitHub releases..."

try {
    $headers = @{ 'User-Agent' = 'rmm-installer' }
    $releaseUrl = "https://api.github.com/repos/$GitHubRepo/releases/latest"
    $release = Invoke-RestMethod -Uri $releaseUrl -Headers $headers -ErrorAction Stop

    # Look for the MSI installer (preferred - sets up PATH and service)
    $msiAsset = $release.assets | Where-Object { $_.name -like "*.msi" } | Select-Object -First 1

    if ($msiAsset) {
        $MsiUrl = $msiAsset.browser_download_url
        Log "Found MSI: $($msiAsset.name) from release $($release.tag_name)"

        Log "Downloading MSI from $MsiUrl..."
        Invoke-WebRequest -Uri $MsiUrl -OutFile $TempMsiPath -UseBasicParsing
        Unblock-File -Path $TempMsiPath -ErrorAction SilentlyContinue

        # Add Windows Defender exclusion
        try {
            Add-MpPreference -ExclusionPath "$env:ProgramFiles\RMM Agent" -ErrorAction SilentlyContinue
            Add-MpPreference -ExclusionPath $AgentRoot -ErrorAction SilentlyContinue
            Log "Added Defender exclusions"
        } catch {
            Log "WARNING: Could not add Defender exclusion"
        }

        Log "Installing MSI (this will install service and add to PATH)..."
        $process = Start-Process "msiexec.exe" -ArgumentList "/i `"$TempMsiPath`" /qn /norestart" -Wait -PassThru

        if ($process.ExitCode -eq 0) {
            Log "MSI installed successfully"
            Remove-Item $TempMsiPath -Force -ErrorAction SilentlyContinue

            # Refresh PATH for current session
            $env:Path = [System.Environment]::GetEnvironmentVariable("Path", "Machine") + ";" + [System.Environment]::GetEnvironmentVariable("Path", "User")

            # Configure server URL (rmm should now be in PATH)
            Log "Configuring server URL: $ServerUrl"
            & rmm --url $ServerUrl 2>&1 | Out-Null

            Start-Sleep -Seconds 2

            # Verify service is running
            $service = Get-Service -Name "RMMAgent" -ErrorAction SilentlyContinue
            if ($service -and $service.Status -eq 'Running') {
                Log "RMM Agent service is running"
            } else {
                Log "WARNING: Service may not have started correctly. Check logs at $AgentRoot\agent.log"
            }
        } else {
            Log "ERROR: MSI installation failed with exit code $($process.ExitCode)"
            exit 1
        }
    } else {
        Log "ERROR: No MSI installer found in latest release."
        exit 1
    }
} catch {
    Log "ERROR: Failed to download/install agent: $_"
    exit 1
}


# ===================================================================
# DONE
# ===================================================================

Log "===== RMM Agent Installation Complete! ====="
Log "The agent service will now handle device enrollment and metrics collection."
Write-Host ""
Write-Host "Installation finished successfully!" -ForegroundColor Green
Write-Host "The RMM agent is now running as a Windows service." -ForegroundColor Green
Write-Host "Check the web panel to approve this device." -ForegroundColor Yellow
Write-Host ""
Write-Host "The 'rmm' command is now available in PATH. Useful commands:" -ForegroundColor Cyan
Write-Host "  rmm status      - Show agent status"
Write-Host "  rmm stop        - Stop the service"
Write-Host "  rmm start       - Start the service"
Write-Host "  rmm --reset     - Force re-enrollment"
PS1;

        $content = str_replace('{BASE_URL}', $base, $script);

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="agent-install.ps1"',
        ]);
    }
}
