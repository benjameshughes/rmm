# Restores files from a backup snapshot into a new folder on this PC, never
# overwriting anything already there. SnapshotId is a snapshot ID or latest;
# IncludePath limits the restore to one file or folder, written as it was on
# the PC (C:\Users\anna\Documents\Quotes); Target is where it goes, by default
# C:\Restore\<date-time>. Files land under Target as their full path, for
# example Target\C\Users\anna\Documents\Quotes.
#
# Restoring another PC's backup (SourceDevice on the Backups tab) works the
# same: the RMM hands over that PC's repository credentials instead of this
# one's. Afterwards the restored files take their permissions from the
# folder they are in, so an admin can open them here. /L resets a restored
# link itself and never walks through it to wherever it points.

$maxErrors = 20

$snapshotId = "$env:RMM_SnapshotId".Trim()
$includePath = "$env:RMM_IncludePath".Trim()
$target = "$env:RMM_Target".Trim().TrimEnd('\')

if (-not $snapshotId) {
    $snapshotId = 'latest'
}

if ($snapshotId -notmatch '^(latest|[0-9a-fA-F]{8,64})$') {
    Stop-Run "'$snapshotId' is not a snapshot ID. Use the ID from the Backups tab, or latest"
}

if (-not $target) {
    $target = "C:\Restore\$(Get-Date -Format 'yyyyMMdd-HHmm')"
}

if ($target -notmatch '^[A-Za-z]:\\[^<>:"|?*\x00-\x1f]+$' -or $target -match '\\\.\.?(\\|$)') {
    Stop-Run "'$target' is not a folder path on this PC. Use something like C:\Restore\Anna"
}

if ($includePath -match '[<>"|?*\x00-\x1f]') {
    Stop-Run "'$includePath' is not a file or folder path"
}

if (-not (Test-BackupCredentials)) {
    Stop-Run 'the RMM sent no backup credentials for the PC being restored. Set them on its Backups tab'
}

Use-Restic
Set-ResticRepository

$arguments = @('restore', $snapshotId, '--target', $target, '--overwrite', 'never', '--json') + $resticArguments

# restic stores C:\Users\anna as /C/Users/anna inside a snapshot.
if ($includePath) {
    $snapshotPath = ($includePath -replace '^([A-Za-z]):', '/$1') -replace '\\', '/'
    $arguments += @('--iinclude', $snapshotPath)
}

New-Item -ItemType Directory -Path $target -Force | Out-Null

$run = Invoke-Restic $arguments
$exitCode = [int]$run.ExitCode
$summaryLine = $run.Stdout | Where-Object { $_ -match '^\{"message_type":"summary"' } | Select-Object -Last 1
$summary = if ($summaryLine) { $summaryLine | ConvertFrom-Json }
$errors = @(Get-ResticErrors $run.Stderr $maxErrors)

if ($exitCode -eq 0) {
    icacls.exe $target /reset /T /C /L /Q | Out-Null
}

$source = "$env:RMM_RestUser".Trim()
$verdict = if ($exitCode -eq 0) { "OK: restored $($summary.files_restored) files from $source snapshot $snapshotId to $target" } else { "ATTENTION: $(Get-ResticExitMeaning $exitCode)" }
Write-Output $verdict

if ($summary.files_skipped) {
    Write-Output "$($summary.files_skipped) files were already in $target and were left alone"
}

foreach ($line in $errors) {
    Write-Output "  $line"
}

$result = @{
    status = if ($exitCode -eq 0) { 'ok' } else { 'failed' }
    exit_code = $exitCode
    snapshot = $snapshotId
    source = $source
    target = $target
    include = $includePath
    total_files = $summary.total_files
    files_restored = $summary.files_restored
    files_skipped = $summary.files_skipped
    total_bytes = $summary.total_bytes
    bytes_restored = $summary.bytes_restored
    errors = $errors
}

Write-Output (ConvertTo-Json -InputObject $result -Depth 4 -Compress)
exit $exitCode
