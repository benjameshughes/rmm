# Sets a password chosen on the Staff page on one staff account's local
# Windows user (RMM_Username). The password (RMM_StaffPassword) is handed
# over once when the agent fetches the command and is never printed. A blank
# one means it expired, was already handed over or the staff account was
# deleted, so the run is refused.
#
# The user must already exist: a sync creates it. Unlike a reset it is not
# made to change the password at next logon (a pending change is cleared), and PasswordNeverExpires is left
# as the sync set it.
#
# Prints OK: or ATTENTION: first, then one JSON line (rmm.set-local-password/1).

$ErrorActionPreference = 'Stop'

$username = "$env:RMM_Username".Trim()
$password = "$env:RMM_StaffPassword"
Remove-Item -Path Env:\RMM_StaffPassword -ErrorAction SilentlyContinue

$result = [ordered]@{
    schema = 'rmm.set-local-password/1'
    username = $username
    password_set = $false
    error = $null
}

# Prints the verdict and the JSON line, then ends the run.
function Stop-SetPassword([string] $message) {
    Write-Output "ATTENTION: $message"
    $result.error = $message
    Write-Output (ConvertTo-Json -InputObject $result -Compress)
    exit 1
}

trap { Stop-SetPassword "Could not set the password for ${username}: $($_.Exception.Message)" }

if ($username -eq '') { Stop-SetPassword 'No username was given' }
if ($password -eq '') { Stop-SetPassword "No password reached the PC for ${username}: it expired, was already used or the staff account was deleted" }

$user = Get-LocalUser -Name $username -ErrorAction SilentlyContinue

if (-not $user) { Stop-SetPassword "$username does not exist on this PC, so sync the staff account first" }

if (@('500', '501', '503', '504') -contains $user.SID.Value.Split('-')[-1]) {
    Stop-SetPassword "$username is a built-in Windows account and is never managed by the RMM"
}

$securePassword = ConvertTo-SecureString -String $password -AsPlainText -Force
$password = $null

Set-LocalUser -InputObject $user -Password $securePassword

# A reset leaves "change at next logon" set, which would throw the chosen
# password away at the first sign-in, so it is cleared.
net.exe user $username /logonpasswordchg:no | Out-Null
if ($LASTEXITCODE -ne 0) { Stop-SetPassword "Set the password for $username but could not clear change at next logon (net user exit $LASTEXITCODE)" }
$result.password_set = $true

Write-Output "OK: Set the chosen password for $username"
Write-Output (ConvertTo-Json -InputObject $result -Compress)
exit 0
