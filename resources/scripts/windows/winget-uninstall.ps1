# Uninstalls one app by its winget package ID, passed by the agent as the
# RMM_PackageId environment variable so the value never becomes script text.
#
# winget ships inside the per-user App Installer package, so SYSTEM has no
# `winget` on its PATH even when it is installed. The machine-wide copy lives
# under WindowsApps; when App Installer is missing entirely, Microsoft's
# WinGet module bootstraps it. ARP and MSIX IDs from the inventory contain
# backslashes, spaces and brackets (ARP\Machine\X86\Microsoft Copilot), so
# any printable text is allowed. Double quotes are refused: Windows
# PowerShell can split an argument holding one into several.

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# winget's "no installed package found" result: already gone is not a failure.
$notInstalled = @(-1978335212)

$packageId = "$env:RMM_PackageId".Trim()

if (-not $packageId) {
    Write-Output 'ATTENTION: No package ID was given. Set the Package ID parameter, for example Mozilla.Firefox'
    exit 1
}

if ($packageId -notmatch '^[A-Za-z0-9][^"\x00-\x1f]*$') {
    Write-Output "ATTENTION: '$packageId' is not a valid package ID. Use the ID from the software inventory, for example Mozilla.Firefox"
    exit 1
}

function Find-WinGet {
    Get-ChildItem -Path "$env:ProgramFiles\WindowsApps\Microsoft.DesktopAppInstaller_*_x64__8wekyb3d8bbwe\winget.exe" -ErrorAction SilentlyContinue |
        Sort-Object { [version](($_.Directory.Name -split '_')[1]) } -Descending |
        Select-Object -First 1 -ExpandProperty FullName
}

$winget = Find-WinGet
$bootstrapLog = ''

if (-not $winget) {
    $bootstrapLog = & {
        Install-PackageProvider -Name NuGet -MinimumVersion 2.8.5.201 -Force -Scope AllUsers
        Install-Module -Name Microsoft.WinGet.Client -Repository PSGallery -Force -Scope AllUsers
        Import-Module Microsoft.WinGet.Client
        Repair-WinGetPackageManager -AllUsers -Latest
    } 2>&1 | Out-String

    $winget = Find-WinGet
}

if (-not $winget) {
    Write-Output 'ATTENTION: winget is not installed and could not be installed'
    Write-Output $bootstrapLog
    exit 1
}

# winget.exe resolves its runtime DLLs from its own folder when run as SYSTEM.
# Some uninstallers (Slack's, for one) wait for the app to close, which nobody
# can do when this runs as SYSTEM. With CloseApp set, processes named after the
# last part of the package ID (SlackTechnologies.Slack -> slack) are closed first.
if ("$env:RMM_CloseApp" -eq 'true') {
    $appName = ($packageId -split '[\\.]')[-1]
    Get-Process -Name $appName -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
}

Push-Location (Split-Path $winget)
$output = & $winget uninstall --id $packageId --exact --silent --accept-source-agreements --disable-interactivity 2>&1 | Out-String
$exitCode = $LASTEXITCODE
Pop-Location

if ($notInstalled -contains $exitCode) {
    Write-Output "OK: $packageId is not installed"
    Write-Output $output
    exit 0
}

if ($exitCode -ne 0) {
    Write-Output "ATTENTION: winget could not uninstall $packageId (exit code $exitCode)"
    Write-Output $output
    exit 1
}

Write-Output "OK: $packageId uninstalled"
Write-Output $output
exit 0
