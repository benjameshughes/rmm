# Lists every installed package winget can see and prints it as ONE line of
# compact JSON for the server to store: id, name, installed_version,
# latest_version, is_update_available and source. Entries winget only knows
# from Add/Remove Programs or MSIX (ARP\..., MSIX\...) have no source and are
# kept with source null.
#
# winget ships inside the per-user App Installer package, so SYSTEM has no
# `winget` on its PATH even when it is installed. The machine-wide copy lives
# under WindowsApps; when App Installer is missing entirely, Microsoft's
# WinGet module bootstraps it. Get-WinGetPackage comes from the same
# Microsoft.WinGet.Client module, which is installed when missing.

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ProgressPreference = 'SilentlyContinue'

function Find-WinGet {
    Get-ChildItem -Path "$env:ProgramFiles\WindowsApps\Microsoft.DesktopAppInstaller_*_x64__8wekyb3d8bbwe\winget.exe" -ErrorAction SilentlyContinue |
        Sort-Object { [version](($_.Directory.Name -split '_')[1]) } -Descending |
        Select-Object -First 1 -ExpandProperty FullName
}

$bootstrapLog = ''

if (-not (Get-Module -ListAvailable -Name Microsoft.WinGet.Client)) {
    $bootstrapLog += & {
        Install-PackageProvider -Name NuGet -MinimumVersion 2.8.5.201 -Force -Scope AllUsers
        Install-Module -Name Microsoft.WinGet.Client -Repository PSGallery -Force -Scope AllUsers
    } 2>&1 | Out-String
}

Import-Module Microsoft.WinGet.Client -ErrorAction SilentlyContinue

if (-not (Get-Command Get-WinGetPackage -ErrorAction SilentlyContinue)) {
    Write-Output 'ATTENTION: the Microsoft.WinGet.Client module is not installed and could not be installed'
    Write-Output $bootstrapLog
    exit 1
}

if (-not (Find-WinGet)) {
    $bootstrapLog += & { Repair-WinGetPackageManager -AllUsers -Latest } 2>&1 | Out-String
}

if (-not (Find-WinGet)) {
    Write-Output 'ATTENTION: winget is not installed and could not be installed'
    Write-Output $bootstrapLog
    exit 1
}

$listErrors = $null
$packages = @(Get-WinGetPackage -ErrorAction SilentlyContinue -ErrorVariable listErrors)

if ($listErrors -and $packages.Count -eq 0) {
    Write-Output "ATTENTION: winget could not list installed packages: $($listErrors[0])"
    exit 1
}

$inventory = @($packages | ForEach-Object {
    $latest = $null
    if ($_.AvailableVersions -and $_.AvailableVersions.Count -gt 0) {
        $latest = [string]$_.AvailableVersions[0]
    }

    $source = $null
    if ($_.Source) {
        $source = [string]$_.Source
    }

    [ordered]@{
        id = [string]$_.Id
        name = [string]$_.Name
        installed_version = [string]$_.InstalledVersion
        latest_version = $latest
        is_update_available = [bool]$_.IsUpdateAvailable
        source = $source
    }
})

# ConvertTo-Json turns a one-item array into an object on PowerShell 5.1, so
# the array brackets are written by hand around each compact item.
$items = $inventory | ForEach-Object { ConvertTo-Json -InputObject $_ -Compress }
Write-Output ('[' + ($items -join ',') + ']')
exit 0
