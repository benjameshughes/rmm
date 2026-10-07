# Backs up every real user profile on the PC to its own restic repository on
# the backup server, reading from a Volume Shadow Copy so open files come out
# whole. Run from a schedule it first waits a random 0 to StaggerMinutes, so
# the PCs do not all upload at once; Back up now on the Backups tab passes 0.
#
# A PC without backups enabled in the RMM is skipped with exit 0, so a
# schedule across a whole group stays quiet. The first backup creates the
# PC's repository on the backup server. Each whole C:\Users\<name> is
# backed up, less caches, temp files, Store app data (apart from Sticky
# Notes), Outlook's .ost cache, registry hives and anything over 4 GB. The
# exclude rules ignore case.
#
# Exit 0: backed up (or nothing had changed). Exit 3: backed up, but some
# files could not be read; they are listed. Anything else: no snapshot, with
# restic's reason. The last line is one JSON object for the Backups tab.

$maxErrors = 20

# restic patterns: no leading slash matches at any depth. A negated (!) rule
# brings back what the rules above it excluded, so Sticky Notes' LocalState
# survives the Packages rule, and the rules after it still apply inside.
$excludes = @(
    'AppData\Local\Packages\*',
    '!AppData\Local\Packages\Microsoft.MicrosoftStickyNotes_8wekyb3d8bbwe',
    'AppData\Local\Packages\Microsoft.MicrosoftStickyNotes_8wekyb3d8bbwe\*',
    '!AppData\Local\Packages\Microsoft.MicrosoftStickyNotes_8wekyb3d8bbwe\LocalState',
    'AppData\Local\Temp',
    'AppData\Local\Microsoft\Windows\INetCache',
    'Cache*',
    'node_modules',
    '$Recycle.Bin',
    '*.ost',
    '*.tmp',
    '~$*',
    'NTUSER.DAT*',
    'UsrClass.dat*',
    'Thumbs.db'
)

# The first backup finds no repository (restic exit 10) and creates it with
# the repository password from the RMM. Append-only rest-server lets a PC
# create a repository; it just can never delete from one. Call it as a
# statement, so a Stop-Run inside still prints.
function Initialize-ResticRepository {
    $init = Invoke-Restic (@('init') + $resticArguments)

    if ([int]$init.ExitCode -ne 0) {
        Stop-Run "could not create the repository for $env:RMM_RepositoryName on the backup server: $((Get-ResticErrors $init.Stderr 3) -join '; ')" ([int]$init.ExitCode)
    }

    Write-Output "Created the repository for $env:RMM_RepositoryName on the backup server"
}

function Format-Bytes($bytes) {
    $value = [double]("0$bytes")
    if ($value -ge 1GB) { return '{0:N1} GB' -f ($value / 1GB) }
    if ($value -ge 1MB) { return '{0:N1} MB' -f ($value / 1MB) }
    return '{0:N0} KB' -f ($value / 1KB)
}

$stagger = "$env:RMM_StaggerMinutes".Trim()
$uploadLimit = "$env:RMM_UploadLimitKiB".Trim()

if ($stagger -notmatch '^\d{0,3}$') {
    Stop-Run "the random start delay must be a whole number of minutes, not '$stagger'"
}

if ($uploadLimit -notmatch '^\d{0,7}$') {
    Stop-Run "the upload limit must be a whole number of KiB/s, not '$uploadLimit'"
}

if (-not (Test-BackupCredentials)) {
    Write-Output 'OK: backups are not set up for this PC in the RMM, so nothing was backed up'
    Write-Output (ConvertTo-Json -InputObject @{ status = 'skipped'; exit_code = 0 } -Compress)
    exit 0
}

Use-Restic
Set-ResticRepository

if ([int]$stagger -gt 0) {
    Start-Sleep -Seconds (Get-Random -Minimum 0 -Maximum ([int]$stagger * 60 + 1))
}

$profiles = @(Get-CimInstance -ClassName Win32_UserProfile -Filter 'Special = FALSE' -ErrorAction SilentlyContinue |
    Where-Object { $_.SID -like 'S-1-5-21-*' -and $_.LocalPath -and (Test-Path -LiteralPath $_.LocalPath) } |
    ForEach-Object { $_.LocalPath } |
    Sort-Object)

if ($profiles.Count -eq 0) {
    Stop-Run 'no user profiles were found to back up'
}

$sourcesFile = Join-Path $resticDir 'sources.txt'
$excludesFile = Join-Path $resticDir 'excludes.txt'
$utf8 = New-Object Text.UTF8Encoding $false
[IO.File]::WriteAllLines($sourcesFile, [string[]]$profiles, $utf8)
[IO.File]::WriteAllLines($excludesFile, [string[]]$excludes, $utf8)

$arguments = @(
    'backup',
    '--files-from-verbatim', $sourcesFile,
    '--iexclude-file', $excludesFile,
    '--exclude-larger-than', '4G',
    '--use-fs-snapshot',
    '--skip-if-unchanged',
    '--exclude-caches',
    '--exclude-cloud-files',
    '--host', $env:COMPUTERNAME,
    '--tag', 'rmm',
    '--json'
) + $resticArguments

if ([int]$uploadLimit -gt 0) {
    $arguments += @('--limit-upload', $uploadLimit)
}

$run = Invoke-Restic $arguments

if ([int]$run.ExitCode -eq 10) {
    Initialize-ResticRepository
    $run = Invoke-Restic $arguments
}

$exitCode = [int]$run.ExitCode
$summaryLine = $run.Stdout | Where-Object { $_ -match '^\{"message_type":"summary"' } | Select-Object -Last 1
$summary = if ($summaryLine) { $summaryLine | ConvertFrom-Json }
$errorLines = @($run.Stderr | Where-Object { "$_" -match '^\{"message_type":"error"' })
$errors = @()

if ($exitCode -ne 0) {
    $errors = @(Get-ResticErrors $(if ($errorLines.Count -gt 0) { $errorLines } else { $run.Stderr }) $maxErrors)
}

$verdict = switch ($exitCode) {
    0 {
        if ($summary.snapshot_id) {
            "OK: backed up $($profiles.Count) user profiles: $($summary.files_new) new and $($summary.files_changed) changed files, $(Format-Bytes $summary.data_added) added"
        }
        else {
            'OK: nothing had changed since the last backup, so no new snapshot was needed'
        }
    }
    3 { "ATTENTION: backed up, but $($errorLines.Count) files or folders could not be read" }
    default { "ATTENTION: $(Get-ResticExitMeaning $exitCode)" }
}

Write-Output $verdict
Write-Output "Profiles: $($profiles -join ', ')"
foreach ($line in $errors) {
    Write-Output "  $line"
}

$result = @{
    status = switch ($exitCode) { 0 { 'ok' } 3 { 'partial' } default { 'failed' } }
    exit_code = $exitCode
    snapshot_id = $summary.snapshot_id
    files_new = $summary.files_new
    files_changed = $summary.files_changed
    files_unmodified = $summary.files_unmodified
    data_added = $summary.data_added
    total_bytes_processed = $summary.total_bytes_processed
    total_duration = $summary.total_duration
    errors = @($errors)
    restic_version = $resticVersion
    sources = @($profiles)
}

Write-Output (ConvertTo-Json -InputObject $result -Depth 4 -Compress)
exit $exitCode
