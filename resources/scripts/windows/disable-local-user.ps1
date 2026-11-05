# Disables one local Windows user (RMM_Username) that the RMM does not
# manage, so nobody can sign in with it. The account, its group memberships
# and its profile are left in place. Never touches a built-in account
# (Administrator, Guest, DefaultAccount, WDAGUtilityAccount, found by their
# well-known RIDs).
#
# Prints OK: or ATTENTION: first.

$ErrorActionPreference = 'Stop'

$username = "$env:RMM_Username".Trim()

if ($username -eq '') {
    Write-Output 'ATTENTION: No username was given'
    exit 1
}

$user = Get-LocalUser -Name $username -ErrorAction SilentlyContinue

if (-not $user) {
    Write-Output "OK: $username is not on this PC"
    exit 0
}

if (@('500', '501', '503', '504') -contains $user.SID.Value.Split('-')[-1]) {
    Write-Output "ATTENTION: $username is a built-in Windows account and is never changed by the RMM"
    exit 1
}

if (-not $user.Enabled) {
    Write-Output "OK: $username was already disabled"
    exit 0
}

trap {
    Write-Output "ATTENTION: Could not disable ${username}: $($_.Exception.Message)"
    exit 1
}

Disable-LocalUser -InputObject $user

Write-Output "OK: Disabled $username"
exit 0
