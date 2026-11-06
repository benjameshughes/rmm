# Restores a backed-up profile's own folders (Desktop, Documents and the
# rest of RMM_RestoreProfileFolders, from config/backup.php) into the same
# folders of a staff account's profile on this PC, never overwriting a file
# already there. SourceProfile is the profile folder as it was on the backed
# up PC (C:\Users\SOPHIE.SMITH), Username the local account to restore into.
#
# The account's SID is looked up here and its profile path read from the
# ProfileList, never assumed. A profile that does not exist yet is refused:
# syncing the staff account creates it. restic restores into a staging
# folder inside the locked restic folder, robocopy then copies across only
# files the profile does not have (/XC /XN /XO skip every file that exists),
# and the account is made owner of each folder and given full control that
# its files inherit, by SID. The staging folder is removed with rmdir, which
# never walks through a restored link.
#
# Restoring another PC's backup (SourceDevice) works as for backup-restore.

$maxErrors = 20

$snapshotId = "$env:RMM_SnapshotId".Trim()
$sourceProfile = "$env:RMM_SourceProfile".Trim().TrimEnd('\')
$username = "$env:RMM_Username".Trim()
# Windows PowerShell 5.1 emits a JSON array as one object, so wrapping the
# pipeline in @() makes a one-item list holding the whole array. Assigning
# first and then wrapping gives the folders as separate items.
$parsedFolders = "$env:RMM_RestoreProfileFolders" | ConvertFrom-Json
$folders = @($parsedFolders)

if (-not $snapshotId) {
    $snapshotId = 'latest'
}

if ($snapshotId -notmatch '^(latest|[0-9a-fA-F]{8,64})$') {
    Stop-Run "'$snapshotId' is not a snapshot ID. Use the ID from the Backups tab, or latest"
}

if ($sourceProfile -notmatch '^[A-Za-z]:\\[^<>:"|?*\x00-\x1f]+$' -or $sourceProfile -match '\\\.\.?(\\|$)') {
    Stop-Run "'$sourceProfile' is not a profile folder. Use it as it was on the PC, like C:\Users\SOPHIE.SMITH"
}

if ($folders.Count -eq 0 -or ($folders | Where-Object { "$_" -notmatch '^[A-Za-z0-9 ._-]+$' -or "$_" -match '^\.+$' })) {
    Stop-Run 'the RMM sent no usable list of profile folders to restore'
}

$user = Get-LocalUser -Name $username -ErrorAction SilentlyContinue

if (-not $user) {
    Stop-Run "there is no local account called '$username' on this PC. Sync the staff account first"
}

$sid = $user.SID.Value
$profileKey = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$sid"
$profileImagePath = (Get-ItemProperty -LiteralPath $profileKey -Name ProfileImagePath -ErrorAction SilentlyContinue).ProfileImagePath
$profilePath = if ($profileImagePath) { [Environment]::ExpandEnvironmentVariables($profileImagePath) }

if (-not $profilePath -or -not (Test-Path -LiteralPath $profilePath -PathType Container)) {
    Stop-Run "$username has no profile on this PC yet. Sync the staff account to create it, then restore again"
}

if (-not (Test-BackupCredentials)) {
    Stop-Run 'the RMM sent no backup credentials for the PC being restored. Enable backups on its Backups tab'
}

Use-Restic
Set-ResticRepository

$staging = Join-Path $resticDir "staging\$([guid]::NewGuid().ToString('N'))"

# restic stores C:\Users\anna as /C/Users/anna inside a snapshot.
$snapshotProfile = ($sourceProfile -replace '^([A-Za-z]):', '/$1') -replace '\\', '/'
# --tag rmm makes latest mean the newest file backup, never a Migrate User capture (tag usmt).
$arguments = @('restore', $snapshotId, '--tag', 'rmm', '--target', $staging, '--json') + $resticArguments

foreach ($folder in $folders) {
    $arguments += @('--iinclude', "$snapshotProfile/$folder")
}

New-Item -ItemType Directory -Path $staging -Force | Out-Null

$run = Invoke-Restic $arguments
$exitCode = [int]$run.ExitCode
$summaryLine = $run.Stdout | Where-Object { $_ -match '^\{"message_type":"summary"' } | Select-Object -Last 1
$summary = if ($summaryLine) { $summaryLine | ConvertFrom-Json }
$errors = @(Get-ResticErrors $run.Stderr $maxErrors)
$restored = @()

# Staged as <staging>\C\Users\SOPHIE.SMITH, the drive letter its own folder.
$stagedProfile = Join-Path $staging ($sourceProfile -replace '^([A-Za-z]):', '$1')

if ($exitCode -eq 0) {
    foreach ($folder in $folders) {
        $from = Join-Path $stagedProfile $folder
        $to = Join-Path $profilePath $folder

        if (-not (Test-Path -LiteralPath $from -PathType Container)) {
            continue
        }

        robocopy.exe $from $to /E /XC /XN /XO /XJ /R:1 /W:1 /NP /NFL /NDL /NJH /NJS | Out-Null

        if ($LASTEXITCODE -ge 8) {
            $errors += "robocopy could not copy $folder (exit $LASTEXITCODE)"
            $exitCode = 1
            continue
        }

        icacls.exe $to /setowner "*$sid" /T /C /L /Q | Out-Null
        icacls.exe $to /grant "*${sid}:(OI)(CI)F" /C /L /Q | Out-Null
        $restored += $folder
    }
}

cmd.exe /c rmdir /s /q "`"$staging`"" | Out-Null

$source = "$env:RMM_RepositoryName".Trim()
$verdict = if ($exitCode -eq 0) { "OK: restored $($restored -join ', ') from $source snapshot $snapshotId ($sourceProfile) into $username's profile at $profilePath" } elseif ($run.ExitCode -ne 0) { "ATTENTION: $(Get-ResticExitMeaning ([int]$run.ExitCode))" } else { "ATTENTION: some folders could not be copied into $profilePath" }
Write-Output $verdict

if ($restored.Count -eq 0 -and $exitCode -eq 0) {
    Write-Output "The snapshot held none of $($folders -join ', ') under $sourceProfile"
}

foreach ($line in $errors) {
    Write-Output "  $line"
}

$result = @{
    status = if ($exitCode -eq 0) { 'ok' } else { 'failed' }
    exit_code = $exitCode
    snapshot = $snapshotId
    source = $source
    source_profile = $sourceProfile
    username = $username
    profile_path = $profilePath
    folders = $restored
    files_staged = $summary.files_restored
    bytes_staged = $summary.bytes_restored
    errors = $errors
}

Write-Output (ConvertTo-Json -InputObject $result -Depth 4 -Compress)
exit $exitCode
