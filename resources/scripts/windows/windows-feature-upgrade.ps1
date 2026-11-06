# Uses the built-in Windows Update Agent API (no PSWindowsUpdate module needed).
# Installs feature upgrades only (new Windows versions such as 25H2). Expect a
# long restart afterwards. Never reboots on its own.
#
# Microsoft only offers a new version to someone clicking Check for updates
# in Settings, never to a background search like this one, unless the PC is
# pinned to it with the TargetReleaseVersion policy. TargetVersion (26H2)
# sets that pin first, for the Windows the PC already runs, so it never
# crosses from Windows 10 to 11. The pin also stops it moving past that
# version until it is changed. Empty TargetVersion leaves the policy alone.

$targetVersion = "$env:RMM_TargetVersion".Trim().ToUpper()

if ($targetVersion -and $targetVersion -notmatch '^\d{2}H[12]$') {
    Write-Output "ATTENTION: '$targetVersion' is not a Windows version. Use one like 26H2"
    exit 1
}

if ($targetVersion) {
    $build = [int](Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion').CurrentBuildNumber
    $product = if ($build -ge 22000) { 'Windows 11' } else { 'Windows 10' }
    $policy = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate'

    New-Item -Path $policy -Force | Out-Null
    Set-ItemProperty -Path $policy -Name TargetReleaseVersion -Value 1 -Type DWord
    Set-ItemProperty -Path $policy -Name ProductVersion -Value $product -Type String
    Set-ItemProperty -Path $policy -Name TargetReleaseVersionInfo -Value $targetVersion -Type String
    Write-Output "Pinned to $product $targetVersion"
}

$upgradesCategoryId = '3689BDC8-B205-4AF4-8D4A-A63924C5E9D5'

$session = New-Object -ComObject Microsoft.Update.Session
$searcher = $session.CreateUpdateSearcher()

Write-Output 'Searching for feature upgrades...'
$searchResult = $searcher.Search("IsInstalled=0 and Type='Software' and IsHidden=0 and CategoryIDs contains '$upgradesCategoryId'")

if ($searchResult.Updates.Count -eq 0) {
    Write-Output 'No feature upgrades available.'
    exit 0
}

$updates = New-Object -ComObject Microsoft.Update.UpdateColl

$searchResult.Updates | ForEach-Object {
    if (-not $_.EulaAccepted) { $_.AcceptEula() }
    [void]$updates.Add($_)
    Write-Output "Found: $($_.Title)"
}

$downloader = $session.CreateUpdateDownloader()
$downloader.Updates = $updates
[void]$downloader.Download()

$installer = $session.CreateUpdateInstaller()
$installer.Updates = $updates
$installResult = $installer.Install()

# OperationResultCode: 2 = succeeded, 3 = succeeded with errors
Write-Output "Installed $($updates.Count) update(s), result code $($installResult.ResultCode)."

if ($installResult.RebootRequired) {
    Write-Output 'A restart is required to finish installing updates.'
}

if ($installResult.ResultCode -notin 2, 3) {
    exit 1
}
