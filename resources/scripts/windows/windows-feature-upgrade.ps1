# Uses the built-in Windows Update Agent API (no PSWindowsUpdate module needed).
# Installs feature upgrades only (new Windows versions such as 25H2). Expect a
# long restart afterwards. Never reboots on its own.

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
