# Gets Netdata reporting CPU again on a PC whose metrics arrive without it.
# Queued automatically after a few blank reports in a row. Stops at the first
# step that brings system.cpu back:
#   1. Service stopped or API not answering: restart it.
#   2. Windows' English performance counter list (Perflib\009) is corrupt or
#      missing 'Processor', which makes Netdata switch its CPU, disk and
#      network collectors off for good: rebuild it with lodctr /R (64 and
#      32 bit), resync WMI and restart Netdata.
#   3. Counters intact: restart Netdata once.
# A repair that does not work exits 1, so it shows as a failed command.

$ErrorActionPreference = 'SilentlyContinue'
$ProgressPreference = 'SilentlyContinue'

$contextsUrl = 'http://127.0.0.1:19999/api/v3/contexts?scope_contexts=system.cpu'
$perflibKey = 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Perflib\009'

# 'silent' when the API does not answer, 'blind' when it answers without live
# CPU data, 'healthy' otherwise. The error preference above keeps a refused
# connection from printing; the response is cleared first because a failed
# call leaves the variable untouched.
function Get-NetdataState {
    $response = $null
    $response = Invoke-RestMethod -Uri $contextsUrl -TimeoutSec 10 -UseBasicParsing

    if ($null -eq $response) {
        return 'silent'
    }

    $cpu = $response.contexts.'system.cpu'

    if ($null -eq $cpu -or $cpu.live -eq $false) {
        return 'blind'
    }

    return 'healthy'
}

# Restarts the service and polls for up to a minute until CPU is reported.
function Restart-Netdata {
    Restart-Service -Name 'netdata' -Force -WarningAction SilentlyContinue

    foreach ($attempt in 1..12) {
        Start-Sleep -Seconds 5
        $state = Get-NetdataState
        if ($state -eq 'healthy') {
            return $state
        }
    }

    return $state
}

$service = Get-Service -Name 'netdata'

if ($null -eq $service) {
    Write-Output 'ATTENTION: Netdata is not installed; run Install Netdata'
    exit 1
}

$state = Get-NetdataState

if ($state -eq 'healthy') {
    Write-Output 'Nothing to do: Netdata is reporting CPU'
    exit 0
}

$restarted = $false

if ($service.Status -ne 'Running' -or $state -eq 'silent') {
    Write-Output "Netdata service was $($service.Status) and its API $(if ($state -eq 'silent') { 'was not answering' } else { 'was answering' }); restarting it"
    $state = Restart-Netdata
    $restarted = $true
}

if ($state -eq 'healthy') {
    Write-Output 'Netdata is reporting CPU again'
    exit 0
}

$counters = (Get-ItemProperty -Path $perflibKey -Name 'Counter').Counter

if ($counters -notcontains 'Processor') {
    $problem = if ($null -eq $counters) { 'is missing' } else { "has $($counters.Count) entries and no Processor" }
    Write-Output "The Perflib\009 performance counter list $problem; rebuilding it"

    # lodctr sometimes prints 'Unable to rebuild ... error code is 2' for one
    # of the two and still succeeds, so the outcome is judged, not its output.
    foreach ($folder in @("$env:SystemRoot\System32", "$env:SystemRoot\SysWOW64")) {
        Push-Location -Path $folder
        & "$folder\lodctr.exe" /R 2>&1 | Out-Null
        Pop-Location
        Write-Output "Ran lodctr /R from $folder"
    }

    winmgmt /resyncperf 2>&1 | Out-Null
    Write-Output 'Ran winmgmt /resyncperf'

    $counters = (Get-ItemProperty -Path $perflibKey -Name 'Counter').Counter
    Write-Output "The counter list now $(if ($counters -contains 'Processor') { 'includes' } else { 'still lacks' }) Processor; restarting Netdata"
    $state = Restart-Netdata
} elseif (-not $restarted) {
    Write-Output 'Netdata answers without CPU data and the performance counters look intact; restarting it'
    $state = Restart-Netdata
}

if ($state -eq 'healthy') {
    Write-Output 'Netdata is reporting CPU again'
    exit 0
}

Write-Output 'ATTENTION: Netdata still has no CPU data after repair'
exit 1
