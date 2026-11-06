# Moves one user's state into a staff account on this same PC with USMT,
# for example a domain account into a local one before leaving the domain.
# scanstate and loadstate run with /hardlink /nocompress, so files are
# linked rather than copied and need no extra space, and the store is
# removed with rmdir afterwards. Both
# users must be signed out; Username must already exist on this PC.

$fromUser = "$env:RMM_FromUser".Trim()
$username = "$env:RMM_Username".Trim()

if ($fromUser -notmatch $migrationUserPattern) {
    Stop-Run "'$fromUser' is not a user USMT can migrate. Use DOMAIN\name or a local username"
}

if ($username -notmatch $localUserPattern) {
    Stop-Run "'$username' is not a local username"
}

if (-not (Get-LocalUser -Name $username -ErrorAction SilentlyContinue)) {
    Stop-Run "there is no local account called '$username' on this PC. Sync the staff account first"
}

$qualifiedUser = ConvertTo-MigrationUser $fromUser
$targetUser = "$env:COMPUTERNAME\$username"

if ($qualifiedUser -ieq $targetUser) {
    Stop-Run "$qualifiedUser is the account being migrated into. Choose a different user to migrate from"
}

foreach ($signedInCheck in @($qualifiedUser, $targetUser)) {
    if (Test-UserSignedIn $signedInCheck) {
        Stop-Run "$signedInCheck is signed in on this PC. Sign them out, then migrate again"
    }
}

Use-Usmt

$runId = [guid]::NewGuid().ToString('N')
$store = Join-Path $usmtStoreRoot $runId
$scanLog = Join-Path $usmtLogDir "scanstate-$runId.log"
$loadLog = Join-Path $usmtLogDir "loadstate-$runId.log"
$xmlArguments = Get-UsmtXmlArguments
$userArguments = @('/ue:*\*', "/ui:$qualifiedUser")

$scanArguments = @($store) + $userArguments + $xmlArguments + @('/hardlink', '/nocompress', '/o', '/c', '/localonly', "/v:$usmtVerbosity", "/l:$scanLog")
& $scanstate @scanArguments | Out-Null
$scanExitCode = [int]$LASTEXITCODE
$errors = @(Get-UsmtLogErrors $scanLog)
$loadExitCode = $null

if ($scanExitCode -eq 0) {
    $loadArguments = @($store) + $userArguments + $xmlArguments + @('/hardlink', '/nocompress', "/mu:${qualifiedUser}:$targetUser", '/c', "/v:$usmtVerbosity", "/l:$loadLog")
    & $loadstate @loadArguments | Out-Null
    $loadExitCode = [int]$LASTEXITCODE
    $errors += @(Get-UsmtLogErrors $loadLog)
}

# rmdir removes hard links, never the files they point at, so it is safe for
# a hard-link store, and unlike usmtutils /rd it never waits on a prompt
# that SYSTEM cannot answer.
cmd.exe /c rmdir /s /q "`"$store`"" | Out-Null

$isOk = $scanExitCode -eq 0 -and $loadExitCode -eq 0
$exitCode = if ($isOk) { 0 } elseif ($scanExitCode -ne 0) { $scanExitCode } else { $loadExitCode }

$verdict = if ($isOk) { "OK: migrated $qualifiedUser into $targetUser" } elseif ($scanExitCode -ne 0) { "ATTENTION: $(Get-UsmtExitMeaning 'scanstate' $scanExitCode)" } else { "ATTENTION: $(Get-UsmtExitMeaning 'loadstate' $loadExitCode)" }
Write-Output $verdict
Write-Output "Logs: $scanLog, $loadLog"

foreach ($line in $errors) {
    Write-Output "  $line"
}

$result = @{
    status = if ($isOk) { 'ok' } else { 'failed' }
    exit_code = $exitCode
    from_user = $qualifiedUser
    username = $username
    scanstate_exit_code = $scanExitCode
    loadstate_exit_code = $loadExitCode
    errors = $errors
}

Write-Output (ConvertTo-Json -InputObject $result -Depth 4 -Compress)
exit $exitCode
