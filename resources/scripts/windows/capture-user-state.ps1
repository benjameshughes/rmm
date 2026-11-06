# Captures one user's state on this (old) PC with USMT scanstate and backs
# the store up to this PC's restic repository, tagged usmt, for
# apply-user-state to load into a staff account on the new PC. FromUser is
# DOMAIN\name or a local name (this PC's name is put in front). The user
# must be signed out, so their profile is not in use.
#
# ApplyDevice and ApplyUsername are not used here: the RMM reads them when
# this run succeeds and queues apply-user-state on that PC.
#
# The store is written under the locked RMM folder and removed with rmdir
# once it is backed up, whatever the outcome.

$fromUser = "$env:RMM_FromUser".Trim()

if ($fromUser -notmatch $migrationUserPattern) {
    Stop-Run "'$fromUser' is not a user USMT can migrate. Use DOMAIN\name or a local username"
}

$qualifiedUser = ConvertTo-MigrationUser $fromUser

if (Test-UserSignedIn $qualifiedUser) {
    Stop-Run "$qualifiedUser is signed in on this PC. Sign them out, then migrate again"
}

if (-not (Test-BackupCredentials)) {
    Stop-Run 'backups are not set up for this PC. Enable them on its Backups tab and back up once'
}

Use-Restic
Set-ResticRepository
Use-Usmt

$runId = [guid]::NewGuid().ToString('N')
$store = Join-Path $usmtStoreRoot $runId
$log = Join-Path $usmtLogDir "scanstate-$runId.log"
New-Item -ItemType Directory -Path $store -Force | Out-Null

$scanArguments = @($store, '/ue:*\*', "/ui:$qualifiedUser") + (Get-UsmtXmlArguments) + @('/o', '/c', '/localonly', "/v:$usmtVerbosity", "/l:$log")
& $scanstate @scanArguments | Out-Null
$scanExitCode = [int]$LASTEXITCODE
$errors = @(Get-UsmtLogErrors $log)
$summary = $null
$backupExitCode = $null

if ($scanExitCode -eq 0) {
    $tag = 'user=' + ($qualifiedUser -replace '\\', '/')
    $run = Invoke-Restic (@('backup', $store, '--host', $env:COMPUTERNAME, '--tag', 'usmt', '--tag', $tag, '--json') + $resticArguments)
    $backupExitCode = [int]$run.ExitCode
    $summaryLine = $run.Stdout | Where-Object { $_ -match '^\{"message_type":"summary"' } | Select-Object -Last 1
    $summary = if ($summaryLine) { $summaryLine | ConvertFrom-Json }
    $errors += @(Get-ResticErrors $run.Stderr)
}

cmd.exe /c rmdir /s /q "`"$store`"" | Out-Null

$snapshotId = "$($summary.snapshot_id)"
$isOk = $scanExitCode -eq 0 -and $backupExitCode -eq 0 -and $snapshotId -match '^[0-9a-f]{64}$'
$exitCode = if ($isOk) { 0 } elseif ($scanExitCode -ne 0) { $scanExitCode } elseif ($backupExitCode -ne 0) { $backupExitCode } else { 1 }

$verdict = if ($isOk) { "OK: captured $qualifiedUser into snapshot $($snapshotId.Substring(0, 8)) of $env:RMM_RepositoryName" } elseif ($scanExitCode -ne 0) { "ATTENTION: $(Get-UsmtExitMeaning 'scanstate' $scanExitCode)" } elseif ($backupExitCode -ne 0) { "ATTENTION: $(Get-ResticExitMeaning $backupExitCode)" } else { 'ATTENTION: restic finished but reported no snapshot' }
Write-Output $verdict
Write-Output "Log: $log"

foreach ($line in $errors) {
    Write-Output "  $line"
}

$result = @{
    status = if ($isOk) { 'ok' } else { 'failed' }
    exit_code = $exitCode
    from_user = $qualifiedUser
    snapshot_id = if ($isOk) { $snapshotId } else { $null }
    scanstate_exit_code = $scanExitCode
    restic_exit_code = $backupExitCode
    bytes_added = $summary.data_added
    log = $log
    errors = $errors
}

Write-Output (ConvertTo-Json -InputObject $result -Depth 4 -Compress)
exit $exitCode
