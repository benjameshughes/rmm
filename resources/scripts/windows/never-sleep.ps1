# Office PCs on Modern Standby fall back asleep seconds after a magic packet
# wakes them, so the RMM loses them. Keep them awake; only the screen sleeps.

$settings = @('standby-timeout-ac 0', 'standby-timeout-dc 0', 'monitor-timeout-ac 60', 'monitor-timeout-dc 60')

$failed = @($settings | Where-Object {
    powercfg /change $_.Split(' ')[0] $_.Split(' ')[1]
    $LASTEXITCODE -ne 0
})

if ($failed.Count -gt 0) {
    Write-Output "ATTENTION: powercfg could not set: $($failed -join ', ')"
    exit 1
}

Write-Output 'Sleep set to never, screen off after 60 minutes'
exit 0
