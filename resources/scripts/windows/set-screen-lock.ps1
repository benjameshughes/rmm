# Locks every session after RMM_IdleMinutes of no input, machine-wide, through
# the "Interactive logon: Machine inactivity limit" policy
# (InactivityTimeoutSecs). 0 removes the policy, turning the lock off. Windows
# reads it at sign-in, so it takes effect after a restart.
#
# RMM_RequirePasswordOnWake ('true' or 'false') sets the "require sign-in on
# wake" power setting on the active plan, plugged in and on battery.
#
# Safe to run on a schedule: it sets the same values every time.
#
# Prints OK: or ATTENTION: first.

$minutes = 0
$rawMinutes = if ("$env:RMM_IdleMinutes".Trim() -eq '') { '10' } else { "$env:RMM_IdleMinutes".Trim() }

if (-not [int]::TryParse($rawMinutes, [ref] $minutes) -or $minutes -lt 0 -or $minutes -gt 9999) {
    Write-Output "ATTENTION: Idle minutes must be a whole number from 0 to 9999, not '$env:RMM_IdleMinutes'"
    exit 1
}

$requirePassword = "$env:RMM_RequirePasswordOnWake".Trim() -ne 'false'
$key = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System'

trap {
    Write-Output "ATTENTION: Could not set the screen lock: $($_.Exception.Message)"
    exit 1
}

if ($minutes -eq 0) {
    Remove-ItemProperty -Path $key -Name 'InactivityTimeoutSecs' -ErrorAction SilentlyContinue
    $summary = 'Screen lock turned off'
} else {
    Set-ItemProperty -Path $key -Name 'InactivityTimeoutSecs' -Value ($minutes * 60) -Type DWord -ErrorAction Stop
    $summary = "Screens lock after $minutes idle minutes (after a restart)"
}

$lockValue = if ($requirePassword) { 1 } else { 0 }

foreach ($arguments in @(
    @('/SETACVALUEINDEX', 'SCHEME_CURRENT', 'SUB_NONE', 'CONSOLELOCK', $lockValue),
    @('/SETDCVALUEINDEX', 'SCHEME_CURRENT', 'SUB_NONE', 'CONSOLELOCK', $lockValue),
    @('/SETACTIVE', 'SCHEME_CURRENT')
)) {
    $output = & powercfg @arguments 2>&1

    if ($LASTEXITCODE -ne 0) {
        Write-Output "ATTENTION: $summary, but powercfg $($arguments[0]) failed: $output"
        exit 1
    }
}

$wake = if ($requirePassword) { 'required' } else { 'not required' }

Write-Output "OK: $summary"
Write-Output "Sign-in on wake $wake"
exit 0
