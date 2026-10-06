# ===================================================================
#  BenJH RMM Agent Installer
#  - Installs the BenJH RMM agent as a Windows service
#  - Netdata (metrics collector) is queued by the server once the device
#    is approved, so enrolment is not held up by a 100MB download
#  Served by {BASE_URL}/agent/install.ps1
# ===================================================================

$ErrorActionPreference = "Stop"

$ServerUrl    = "{BASE_URL}"
$GitHubRepo   = "benjameshughes/rmm"
$ServiceName  = "BenJHRMM"
$ProductName  = "BenJH RMM"
$LogFile      = Join-Path $env:TEMP "benjh-rmm-install.log"

function Enable-Tls12 {
    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor [System.Net.SecurityProtocolType]::Tls12
}

function Test-Admin {
    $principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Log {
    param([string]$Message)
    "$((Get-Date).ToString('yyyy-MM-dd HH:mm:ss'))  $Message" | Out-File $LogFile -Append
    Write-Host $Message
}

# Win32_Product is avoided on purpose: querying it makes Windows Installer
# consistency-check (and sometimes repair) every MSI on the machine.
function Get-InstalledMsi {
    param([string]$DisplayNamePattern)
    $uninstallKeys = @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    )
    Get-ItemProperty -Path $uninstallKeys -ErrorAction SilentlyContinue |
        Where-Object { $_.DisplayName -like $DisplayNamePattern -and $_.PSChildName -match '^\{[0-9A-Fa-f-]+\}$' }
}

function Uninstall-Msi {
    param($Product)
    Log "Uninstalling $($Product.DisplayName) $($Product.DisplayVersion)..."
    $process = Start-Process "msiexec.exe" -ArgumentList "/x $($Product.PSChildName) /qn /norestart" -Wait -PassThru
    Log "Uninstall finished with exit code $($process.ExitCode)"
}

# Re-launch elevated if needed
if (-not (Test-Admin)) {
    Enable-Tls12
    $elevatedCommand = "[System.Net.ServicePointManager]::SecurityProtocol=[System.Net.SecurityProtocolType]::Tls12; iwr -useb '$ServerUrl/agent/install.ps1' | iex"
    Start-Process PowerShell -Verb RunAs -ArgumentList @("-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", $elevatedCommand)
    exit
}

Enable-Tls12
Log "===== BenJH RMM agent install starting (log: $LogFile) ====="


# ===================================================================
# STEP 1 — REMOVE ANY EXISTING AGENT
# ===================================================================

Get-InstalledMsi -DisplayNamePattern $ProductName | ForEach-Object { Uninstall-Msi $_ }

# Leftovers from pre-MSI versions of the agent
$legacyService = Get-Service -Name "RMMAgent" -ErrorAction SilentlyContinue
if ($legacyService) {
    Log "Removing legacy RMMAgent service..."
    Stop-Service -Name "RMMAgent" -Force -ErrorAction SilentlyContinue
    sc.exe delete "RMMAgent" | Out-Null
}

Get-Process -Name "rmm-agent", "benjh-rmm" -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName "RMM-Metrics-Agent" -Confirm:$false -ErrorAction SilentlyContinue
Remove-ItemProperty -Path "HKLM:\Software\Microsoft\Windows\CurrentVersion\Run" -Name "RMM-Tray" -ErrorAction SilentlyContinue

Log "Existing agent cleanup complete."


# ===================================================================
# STEP 2 — INSTALL THE AGENT
# ===================================================================

Log "Looking up the latest agent release..."
$release = Invoke-RestMethod -Headers @{ 'User-Agent' = 'rmm-installer' } -Uri "https://api.github.com/repos/$GitHubRepo/releases/latest"
$msiAsset = $release.assets | Where-Object { $_.name -like "*.msi" } | Select-Object -First 1

if (-not $msiAsset) {
    Log "ERROR: The latest release ($($release.tag_name)) has no MSI."
    exit 1
}

$agentMsi = Join-Path $env:TEMP "benjh-rmm-agent.msi"
Log "Downloading $($msiAsset.name) from release $($release.tag_name)..."
Invoke-WebRequest -Uri $msiAsset.browser_download_url -OutFile $agentMsi -UseBasicParsing
Unblock-File -Path $agentMsi -ErrorAction SilentlyContinue

Log "Installing the agent..."
$install = Start-Process "msiexec.exe" -ArgumentList "/i `"$agentMsi`" /qn /norestart" -Wait -PassThru
Remove-Item $agentMsi -Force -ErrorAction SilentlyContinue

if ($install.ExitCode -ne 0) {
    Log "ERROR: Agent MSI failed with exit code $($install.ExitCode)"
    exit 1
}

# Point the agent at this server, using the service's own executable path:
# PATH changes from the MSI aren't visible to this session yet.
$service = Get-CimInstance Win32_Service -Filter "Name='$ServiceName'"
if (-not $service) {
    Log "ERROR: The $ServiceName service was not created by the installer."
    exit 1
}

$agentExe = $service.PathName.Trim('"')
Log "Configuring server URL: $ServerUrl"
& $agentExe --url $ServerUrl 2>&1 | ForEach-Object { Log "  $_" }

# The MSI starts the service, and a service that is still starting refuses to
# stop ("Cannot stop BenJHRMM service"), so wait for it to finish before the
# restart that makes it pick up the server URL.
$startDeadline = (Get-Date).AddSeconds(60)
while ((Get-Service -Name $ServiceName).Status -ne 'Running' -and (Get-Date) -lt $startDeadline) {
    Start-Sleep -Seconds 1
}
Restart-Service -Name $ServiceName -Force

Start-Sleep -Seconds 2
if ((Get-Service -Name $ServiceName).Status -eq 'Running') {
    Log "The $ServiceName service is running."
} else {
    Log "WARNING: The $ServiceName service is not running. Check C:\ProgramData\BenJH RMM\agent.log"
}


# ===================================================================
# DONE
# ===================================================================

Log "===== BenJH RMM agent install complete ====="
Write-Host ""
Write-Host "Installation finished." -ForegroundColor Green
Write-Host "Approve this device at $ServerUrl/devices/pending" -ForegroundColor Yellow
Write-Host "Netdata installs itself once approved; metrics appear a few minutes later."
Write-Host ""
Write-Host "Useful commands (from an elevated prompt, new window):" -ForegroundColor Cyan
Write-Host "  rmm status      - Show agent status"
Write-Host "  rmm logs        - Show recent agent logs"
Write-Host "  rmm update      - Install the latest agent now"
