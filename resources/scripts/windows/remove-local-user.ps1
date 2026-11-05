# Deletes one staff account's local Windows user (RMM_Username). Never
# removes a built-in account (Administrator, Guest, DefaultAccount,
# WDAGUtilityAccount, found by their well-known RIDs) or whoever is signed in
# at the console. The profile folder is left on disk.
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
    Write-Output "ATTENTION: $username is a built-in Windows account and is never removed"
    exit 1
}

$consoleUser = (Get-CimInstance -ClassName Win32_ComputerSystem).UserName

if ($consoleUser -and $consoleUser.Split('\')[-1] -ieq $username) {
    Write-Output "ATTENTION: $username is signed in right now, so it was left in place"
    exit 1
}

trap {
    Write-Output "ATTENTION: Could not remove ${username}: $($_.Exception.Message)"
    exit 1
}

Remove-LocalUser -InputObject $user

Write-Output "OK: Removed $username"
exit 0
