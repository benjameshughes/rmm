# Creates or updates one staff account as a local Windows user and brings it
# in line with the RMM: full name, password, password never expires, user
# cannot change it, Administrators membership and enabled or disabled. The
# account arrives as parameters (RMM_Username, RMM_FullName, RMM_IsAdmin,
# RMM_IsEnabled); the password arrives only as the RMM_StaffPassword secret
# when the agent fetches the command and is never printed.
#
# The Administrators group is found by its well-known SID (S-1-5-32-544), so
# a PC in another language works too.
#
# Prints OK: or ATTENTION: first, then one JSON line (rmm.sync-local-user/1).

$ErrorActionPreference = 'Stop'

$username = "$env:RMM_Username".Trim()
$fullName = "$env:RMM_FullName".Trim()
$isAdmin = @('1', 'true') -contains "$env:RMM_IsAdmin".Trim().ToLowerInvariant()
$isEnabled = @('1', 'true') -contains "$env:RMM_IsEnabled".Trim().ToLowerInvariant()
$password = "$env:RMM_StaffPassword"

$result = [ordered]@{
    schema = 'rmm.sync-local-user/1'
    username = $username
    created = $false
    is_admin = $isAdmin
    is_enabled = $isEnabled
    error = $null
}

# Prints the verdict and the JSON line, then ends the run.
function Stop-Sync([string] $message) {
    Write-Output "ATTENTION: $message"
    $result.error = $message
    Write-Output (ConvertTo-Json -InputObject $result -Compress)
    exit 1
}

trap { Stop-Sync "Could not sync ${username}: $($_.Exception.Message)" }

if ($username -eq '') { Stop-Sync 'No username was given' }
if ($password -eq '') { Stop-Sync "No password reached the PC for $username, so the staff account may have been deleted" }

$securePassword = ConvertTo-SecureString -String $password -AsPlainText -Force
$user = Get-LocalUser -Name $username -ErrorAction SilentlyContinue

if ($user -and (@('500', '501', '503', '504') -contains $user.SID.Value.Split('-')[-1])) {
    Stop-Sync "$username is a built-in Windows account and is never managed by the RMM"
}

if ($user) {
    Set-LocalUser -InputObject $user -FullName $fullName -Password $securePassword -PasswordNeverExpires $true -UserMayChangePassword $false
} else {
    $user = New-LocalUser -Name $username -FullName $fullName -Password $securePassword -PasswordNeverExpires -UserMayNotChangePassword
    $result.created = $true
}

$administrators = Get-LocalGroup -SID 'S-1-5-32-544'
$isMember = [bool] (Get-LocalGroupMember -Group $administrators | Where-Object { $_.SID.Value -eq $user.SID.Value })

if ($isAdmin -and -not $isMember) { Add-LocalGroupMember -Group $administrators -Member $user }
if (-not $isAdmin -and $isMember) { Remove-LocalGroupMember -Group $administrators -Member $user }

if ($isEnabled) { Enable-LocalUser -InputObject $user } else { Disable-LocalUser -InputObject $user }

$verb = if ($result.created) { 'Created' } else { 'Updated' }
$role = if ($isAdmin) { 'administrator' } else { 'standard user' }
$state = if ($isEnabled) { 'enabled' } else { 'disabled' }

Write-Output "OK: $verb $username as $role, $state"
Write-Output (ConvertTo-Json -InputObject $result -Compress)
exit 0
