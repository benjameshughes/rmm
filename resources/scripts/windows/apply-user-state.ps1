# Loads a user's state captured by capture-user-state on the old PC into a
# staff account on this (new) PC with USMT loadstate. SnapshotId is the
# capture's restic snapshot in the old PC's repository (SourceDevice, whose
# credentials the RMM hands over as for backup-restore), FromUser the user
# as captured (OLDPC\name or DOMAIN\name) and Username the local staff
# account to load into, which must exist and be signed out.
#
# The store is restored into a staging folder inside the locked restic
# folder and removed with rmdir afterwards, whatever the outcome.

$snapshotId = "$env:RMM_SnapshotId".Trim()
$fromUser = "$env:RMM_FromUser".Trim()
$username = "$env:RMM_Username".Trim()

if ($snapshotId -notmatch '^[0-9a-fA-F]{8,64}$') {
    Stop-Run "'$snapshotId' is not a snapshot ID"
}

if ($fromUser -notmatch $migrationUserPattern -or -not $fromUser.Contains('\')) {
    Stop-Run "'$fromUser' is not a captured user. It must be DOMAIN\name or OLDPC\name"
}

if ($username -notmatch $localUserPattern) {
    Stop-Run "'$username' is not a local username"
}

if (-not (Get-LocalUser -Name $username -ErrorAction SilentlyContinue)) {
    Stop-Run "there is no local account called '$username' on this PC. Sync the staff account first"
}

$targetUser = "$env:COMPUTERNAME\$username"

if (Test-UserSignedIn $targetUser) {
    Stop-Run "$username is signed in on this PC. Sign them out, then migrate again"
}

if (-not (Test-BackupCredentials)) {
    Stop-Run 'the RMM sent no backup credentials for the PC being migrated from. Enable backups on its Backups tab'
}

Use-Restic
Set-ResticRepository
Use-Usmt

$runId = [guid]::NewGuid().ToString('N')
$staging = Join-Path $resticDir "staging\$runId"
$log = Join-Path $usmtLogDir "loadstate-$runId.log"
New-Item -ItemType Directory -Path $staging -Force | Out-Null

$run = Invoke-Restic (@('restore', $snapshotId, '--target', $staging, '--json') + $resticArguments)
$restoreExitCode = [int]$run.ExitCode
$errors = @(Get-ResticErrors $run.Stderr)
$loadExitCode = $null

# scanstate writes <store>\USMT\USMT.MIG; the store is the folder above USMT.
$migFile = if ($restoreExitCode -eq 0) { Get-ChildItem -LiteralPath $staging -Recurse -Filter 'USMT.MIG' -File -ErrorAction SilentlyContinue | Select-Object -First 1 }
$store = if ($migFile) { $migFile.Directory.Parent.FullName }

if ($store) {
    $loadArguments = @($store, '/ue:*\*', "/ui:$fromUser") + (Get-UsmtXmlArguments) + @("/mu:${fromUser}:$targetUser", '/c', "/v:$usmtVerbosity", "/l:$log")
    & $loadstate @loadArguments | Out-Null
    $loadExitCode = [int]$LASTEXITCODE
    $errors += @(Get-UsmtLogErrors $log)
}

cmd.exe /c rmdir /s /q "`"$staging`"" | Out-Null

$isOk = $restoreExitCode -eq 0 -and $loadExitCode -eq 0
$exitCode = if ($isOk) { 0 } elseif ($restoreExitCode -ne 0) { $restoreExitCode } elseif ($null -eq $loadExitCode) { 1 } else { $loadExitCode }
$source = "$env:RMM_RepositoryName".Trim()

$verdict = if ($isOk) { "OK: loaded $fromUser from $source snapshot $snapshotId into $targetUser" } elseif ($restoreExitCode -ne 0) { "ATTENTION: $(Get-ResticExitMeaning $restoreExitCode)" } elseif (-not $store) { "ATTENTION: snapshot $snapshotId holds no USMT store" } else { "ATTENTION: $(Get-UsmtExitMeaning 'loadstate' $loadExitCode)" }
Write-Output $verdict
Write-Output "Log: $log"

foreach ($line in $errors) {
    Write-Output "  $line"
}

$result = @{
    status = if ($isOk) { 'ok' } else { 'failed' }
    exit_code = $exitCode
    snapshot_id = $snapshotId
    source = $source
    from_user = $fromUser
    username = $username
    restic_exit_code = $restoreExitCode
    loadstate_exit_code = $loadExitCode
    log = $log
    errors = $errors
}

Write-Output (ConvertTo-Json -InputObject $result -Depth 4 -Compress)
exit $exitCode
