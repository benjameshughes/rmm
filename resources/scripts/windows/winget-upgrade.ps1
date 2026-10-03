# Upgrades one app by its winget package ID, passed by the agent as the
# RMM_PackageId environment variable so the value never becomes script text.
#
# winget ships inside the per-user App Installer package, so SYSTEM has no
# `winget` on its PATH even when it is installed. The machine-wide copy lives
# under WindowsApps; when App Installer is missing entirely, Microsoft's
# WinGet module bootstraps it. Upgrades are machine scope: a per-user install
# would land in SYSTEM's own profile where nobody uses it.

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# winget's "no applicable upgrade" result: already on the latest version.
$noApplicableUpgrade = @(-1978335189)
# winget's "no installed package found" result.
$notInstalled = @(-1978335212)

$packageId = "$env:RMM_PackageId".Trim()

if (-not $packageId) {
    Write-Output 'ATTENTION: No package ID was given. Set the Package ID parameter, for example Mozilla.Firefox'
    exit 1
}

if ($packageId -notmatch '^[A-Za-z0-9][A-Za-z0-9._+-]*$') {
    Write-Output "ATTENTION: '$packageId' is not a valid winget package ID. Use letters, digits and . _ + - only, for example Mozilla.Firefox"
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
Push-Location (Split-Path $winget)
$output = & $winget upgrade --id $packageId --exact --scope machine --silent --accept-package-agreements --accept-source-agreements --disable-interactivity 2>&1 | Out-String
$exitCode = $LASTEXITCODE
Pop-Location

if ($noApplicableUpgrade -contains $exitCode) {
    Write-Output "OK: $packageId is already on the latest version"
    Write-Output $output
    exit 0
}

if ($notInstalled -contains $exitCode) {
    Write-Output "ATTENTION: $packageId is not installed machine-wide, so winget cannot upgrade it"
    Write-Output $output
    exit 1
}

if ($exitCode -ne 0) {
    Write-Output "ATTENTION: winget could not upgrade $packageId (exit code $exitCode)"
    Write-Output $output
    exit 1
}

Write-Output "OK: $packageId upgraded"
if ($bootstrapLog) {
    Write-Output 'winget was missing and has been installed.'
}
Write-Output $output
exit 0
