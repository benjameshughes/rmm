# Locks every session after RMM_IdleMinutes of no input, machine-wide, through
# the "Interactive logon: Machine inactivity limit" policy
# (InactivityTimeoutSecs). Windows reads it at sign-in, so it takes effect
# after a restart.
#
# Prints OK: or ATTENTION: first.

$minutes = 0

if (-not [int]::TryParse("$env:RMM_IdleMinutes".Trim(), [ref] $minutes) -or $minutes -lt 1 -or $minutes -gt 9999) {
    Write-Output "ATTENTION: Idle minutes must be a whole number from 1 to 9999, not '$env:RMM_IdleMinutes'"
    exit 1
}

$key = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System'

trap {
    Write-Output "ATTENTION: Could not set the screen lock: $($_.Exception.Message)"
    exit 1
}

Set-ItemProperty -Path $key -Name 'InactivityTimeoutSecs' -Value ($minutes * 60) -Type DWord -ErrorAction Stop

Write-Output "OK: Screens lock after $minutes idle minutes (after a restart)"
exit 0
