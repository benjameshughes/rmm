# winget ships inside the per-user App Installer package, so SYSTEM has no
# `winget` on its PATH even when it is installed. The machine-wide copy lives
# under WindowsApps; when App Installer is missing entirely, Microsoft's
# WinGet module bootstraps it. Only machine-scope installs are upgraded:
# per-user apps would land in SYSTEM's own profile where nobody uses them.

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# winget's "no applicable update found" result; nothing to do is not a failure.
$noApplicableUpdate = -1978335189

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
$output = & $winget upgrade --all --scope machine --silent --accept-package-agreements --accept-source-agreements --disable-interactivity 2>&1 | Out-String
$exitCode = $LASTEXITCODE
Pop-Location

$upgraded = ([regex]::Matches($output, 'Successfully installed')).Count
$failed = ([regex]::Matches($output, 'Installer failed|failed with exit code')).Count

if ($exitCode -ne 0 -and $exitCode -ne $noApplicableUpdate) {
    Write-Output "ATTENTION: winget upgrade failed with exit code $exitCode ($upgraded upgraded, $failed failed)"
    Write-Output $output
    exit 1
}

if ($failed -gt 0) {
    Write-Output "ATTENTION: $upgraded apps upgraded, $failed failed"
    Write-Output $output
    exit 1
}

Write-Output "OK: $upgraded apps upgraded"
if ($bootstrapLog) {
    Write-Output 'winget was missing and has been installed.'
}
Write-Output $output
