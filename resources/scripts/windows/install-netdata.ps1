# Installs the one pinned Netdata release the agent reads its metrics from, so
# every PC reports the same metric shapes. The agent installer leaves Netdata
# out to keep enrolment quick; this is queued when the PC is approved. A PC
# already running the pinned version is left alone, keeping its history.
# Either way Netdata is kept listening on 127.0.0.1 only, with anonymous
# statistics off; the service restarts only when its config had to change.

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ProgressPreference = 'SilentlyContinue'

$NetdataVersion = 'v2.12.0'

# Win32_Product is avoided on purpose: querying it makes Windows Installer
# consistency-check (and sometimes repair) every MSI on the machine.
$installed = @(Get-ItemProperty -Path @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    ) -ErrorAction SilentlyContinue |
    Where-Object { $_.DisplayName -like 'Netdata*' -and $_.PSChildName -match '^\{[0-9A-Fa-f-]+\}$' })

$isPinnedVersion = $installed.Count -gt 0 -and ($installed | Where-Object { $_.DisplayVersion -like "$($NetdataVersion.TrimStart('v'))*" })

if (-not $isPinnedVersion) {
    Stop-Service -Name 'netdata' -Force -ErrorAction SilentlyContinue

    $installed | ForEach-Object {
        Write-Output "Uninstalling $($_.DisplayName) $($_.DisplayVersion)"
        Start-Process 'msiexec.exe' -ArgumentList "/x $($_.PSChildName) /qn /norestart" -Wait
    }

    @("$env:ProgramFiles\Netdata", "$env:ProgramData\Netdata") | Where-Object { Test-Path $_ } | ForEach-Object {
        Remove-Item -Path $_ -Recurse -Force -ErrorAction SilentlyContinue
    }

    if (Get-Service -Name 'netdata' -ErrorAction SilentlyContinue) {
        sc.exe delete netdata | Out-Null
    }

    Write-Output "Installing Netdata $NetdataVersion"
    $msi = Join-Path $env:TEMP 'netdata.msi'
    Invoke-WebRequest -Uri "https://github.com/netdata/netdata/releases/download/$NetdataVersion/netdata-x64.msi" -OutFile $msi -UseBasicParsing
    $install = Start-Process 'msiexec.exe' -ArgumentList "/i `"$msi`" /qn /norestart" -Wait -PassThru
    Remove-Item $msi -Force -ErrorAction SilentlyContinue

    if ($install.ExitCode -ne 0) {
        Write-Output "ATTENTION: the Netdata MSI failed with exit code $($install.ExitCode)"
        exit 1
    }
}

$configDir = "$env:ProgramFiles\Netdata\etc\netdata"
$configPath = Join-Path $configDir 'netdata.conf'
$optOutPath = Join-Path $configDir '.opt-out-from-anonymous-statistics'
$bindLine = '    bind to = 127.0.0.1'

New-Item -ItemType Directory -Path $configDir -Force | Out-Null

if (-not (Test-Path $optOutPath)) {
    New-Item -ItemType File -Path $optOutPath | Out-Null
}

# Any other `bind to` is dropped and ours goes straight under [web], adding
# the section when missing, so the rest of an existing config is kept.
$current = if (Test-Path $configPath) { [IO.File]::ReadAllText($configPath) } else { '' }
$lines = @(if ($current.Trim()) { $current.TrimEnd() -split "`r?`n" | Where-Object { $_ -notmatch '^\s*bind to\s*=' } })
$desired = [System.Collections.Generic.List[string]]::new()

foreach ($line in $lines) {
    $desired.Add($line)
    if ($line.Trim() -eq '[web]') {
        $desired.Add($bindLine)
    }
}

if (-not $desired.Contains($bindLine)) {
    $desired.Add('[web]')
    $desired.Add($bindLine)
}

$config = ($desired -join "`r`n") + "`r`n"

if ($config -ne $current) {
    Write-Output 'Restricting Netdata to 127.0.0.1'
    [IO.File]::WriteAllText($configPath, $config)

    if ((Get-Service -Name 'netdata' -ErrorAction SilentlyContinue).Status -eq 'Running') {
        Restart-Service -Name 'netdata' -Force
    }
}

Set-Service -Name 'netdata' -StartupType Automatic -ErrorAction SilentlyContinue
Start-Service -Name 'netdata' -ErrorAction SilentlyContinue
Start-Sleep -Seconds 3

if (-not (Test-NetConnection -ComputerName 127.0.0.1 -Port 19999 -InformationLevel Quiet -WarningAction SilentlyContinue)) {
    Write-Output "ATTENTION: Netdata $NetdataVersion is installed but not listening on port 19999"
    exit 1
}

Write-Output "Netdata $NetdataVersion is running"
exit 0
