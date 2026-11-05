# Creates or updates one staff account as a local Windows user and brings it
# in line with the RMM: full name, Administrators membership and enabled or
# disabled. The account arrives as parameters (RMM_Username, RMM_FullName,
# RMM_IsAdmin, RMM_IsEnabled, RMM_ResetPassword).
#
# The person owns their password. It is only set when the user is created
# here or RMM_ResetPassword is true: then it becomes the temp password
# (RMM_StaffTempPassword, handed over when the agent fetches the command and
# never printed) and must be changed at the next logon. A plain sync never
# touches it. A blank temp password means the staff account was deleted, so
# the run is refused rather than recreating the user.
#
# The Administrators group is found by its well-known SID (S-1-5-32-544), so
# a PC in another language works too.
#
# Once the user exists its profile is created with userenv's CreateProfile,
# so C:\Users\<username> and its ProfileList entry are there before the
# first sign-in and a backup can be restored into it. A SID that already has
# a ProfileList entry is left alone, and ERROR_ALREADY_EXISTS counts as done.
#
# Prints OK: or ATTENTION: first, then one JSON line (rmm.sync-local-user/1).

$ErrorActionPreference = 'Stop'

$username = "$env:RMM_Username".Trim()
$fullName = "$env:RMM_FullName".Trim()
$isAdmin = @('1', 'true') -contains "$env:RMM_IsAdmin".Trim().ToLowerInvariant()
$isEnabled = @('1', 'true') -contains "$env:RMM_IsEnabled".Trim().ToLowerInvariant()
$resetPassword = @('1', 'true') -contains "$env:RMM_ResetPassword".Trim().ToLowerInvariant()
$tempPassword = "$env:RMM_StaffTempPassword"

$result = [ordered]@{
    schema = 'rmm.sync-local-user/1'
    username = $username
    created = $false
    password_reset = $false
    is_admin = $isAdmin
    is_enabled = $isEnabled
    profile_path = $null
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
if ($tempPassword -eq '') { Stop-Sync "No temp password reached the PC for $username, so the staff account may have been deleted" }

$securePassword = ConvertTo-SecureString -String $tempPassword -AsPlainText -Force
$user = Get-LocalUser -Name $username -ErrorAction SilentlyContinue

if ($user -and (@('500', '501', '503', '504') -contains $user.SID.Value.Split('-')[-1])) {
    Stop-Sync "$username is a built-in Windows account and is never managed by the RMM"
}

if ($user) {
    Set-LocalUser -InputObject $user -FullName $fullName -UserMayChangePassword $true
} else {
    $user = New-LocalUser -Name $username -FullName $fullName -Password $securePassword
    $result.created = $true
}

# net user refuses /logonpasswordchg on an account whose password never
# expires, so that flag is cleared first. Clearing it lets the machine's
# maximum password age apply, so that is set to unlimited: staff own their
# password once changed and are never made to rotate it.
if ($result.created -or $resetPassword) {
    net.exe accounts /maxpwage:unlimited | Out-Null
    if ($LASTEXITCODE -ne 0) { Stop-Sync "Could not turn off password expiry on this PC (net accounts exit $LASTEXITCODE)" }
    Set-LocalUser -InputObject $user -Password $securePassword -PasswordNeverExpires $false -UserMayChangePassword $true
    net.exe user $username /logonpasswordchg:yes | Out-Null
    if ($LASTEXITCODE -ne 0) { Stop-Sync "Set the temp password for $username but could not make it change at next logon (net user exit $LASTEXITCODE)" }
    $result.password_reset = $true
}

$administrators = Get-LocalGroup -SID 'S-1-5-32-544'
$isMember = [bool] (Get-LocalGroupMember -Group $administrators | Where-Object { $_.SID.Value -eq $user.SID.Value })

if ($isAdmin -and -not $isMember) { Add-LocalGroupMember -Group $administrators -Member $user }
if (-not $isAdmin -and $isMember) { Remove-LocalGroupMember -Group $administrators -Member $user }

if ($isEnabled) { Enable-LocalUser -InputObject $user } else { Disable-LocalUser -InputObject $user }

$profileKey = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$($user.SID.Value)"

if (-not (Test-Path -LiteralPath $profileKey)) {
    Add-Type -Namespace RmmUserEnv -Name Native -MemberDefinition @'
[DllImport("userenv.dll", CharSet = CharSet.Unicode)]
public static extern int CreateProfile(string pszUserSid, string pszUserName, System.Text.StringBuilder pszProfilePath, uint cchProfilePath);
'@

    $profileBuffer = New-Object System.Text.StringBuilder 260
    $hresult = [RmmUserEnv.Native]::CreateProfile($user.SID.Value, $username, $profileBuffer, [uint32] $profileBuffer.Capacity)

    if ($hresult -ne 0 -and $hresult -ne [int] 0x800700B7) {
        Stop-Sync ("Synced $username but could not create its profile: HRESULT 0x{0:X8}" -f $hresult)
    }
}

$result.profile_path = [Environment]::ExpandEnvironmentVariables((Get-ItemProperty -LiteralPath $profileKey -Name ProfileImagePath -ErrorAction SilentlyContinue).ProfileImagePath)

$verb = if ($result.created) { 'Created' } else { 'Updated' }
$role = if ($isAdmin) { 'administrator' } else { 'standard user' }
$state = if ($isEnabled) { 'enabled' } else { 'disabled' }

$passwordNote = if ($result.password_reset) { ', temp password set, change at next logon' } else { '' }

Write-Output "OK: $verb $username as $role, $state$passwordNote, profile at $($result.profile_path)"
Write-Output (ConvertTo-Json -InputObject $result -Compress)
exit 0
