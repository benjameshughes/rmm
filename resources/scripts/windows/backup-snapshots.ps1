# Lists this PC's backup snapshots on the backup server for its Backups tab.
# Reads only; the repository is append-only to the PC anyway. The last line
# is one JSON object: every snapshot with its time, folders and size.

if (-not (Test-BackupCredentials)) {
    Stop-Run 'backups are not set up for this PC in the RMM. Enable backups on its Backups tab'
}

Use-Restic
Set-ResticRepository

$run = Invoke-Restic (@('snapshots', '--host', $env:COMPUTERNAME, '--json') + $resticArguments)
$exitCode = [int]$run.ExitCode

if ($exitCode -ne 0) {
    Write-Output "ATTENTION: $(Get-ResticExitMeaning $exitCode)"
    $errors = @(Get-ResticErrors $run.Stderr)
    foreach ($line in $errors) {
        Write-Output "  $line"
    }
    Write-Output (ConvertTo-Json -InputObject @{ status = 'failed'; exit_code = $exitCode; errors = $errors } -Depth 3 -Compress)
    exit $exitCode
}

# PowerShell 5.1 hands a parsed JSON array over as one object, so it is
# assigned first and then unrolled through the pipeline.
$parsed = ($run.Stdout -join "`n") | ConvertFrom-Json
$snapshots = @($parsed | Where-Object { $null -ne $_ } | ForEach-Object {
        @{
            id = $_.id
            short_id = $_.short_id
            time = $_.time
            paths = @($_.paths)
            files = $_.summary.total_files_processed
            bytes = $_.summary.total_bytes_processed
            added = $_.summary.data_added
        }
    })

Write-Output "OK: $($snapshots.Count) backup snapshots of $env:COMPUTERNAME"
Write-Output (ConvertTo-Json -InputObject @{ status = 'ok'; exit_code = 0; snapshots = $snapshots } -Depth 4 -Compress)
exit 0
