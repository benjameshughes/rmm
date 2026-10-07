# Removes every job from one print queue. The printer name arrives as the
# RMM_PrinterName environment variable, so the value never becomes script
# text. Printer names hold spaces, '#' and brackets, and Get-Printer -Name
# treats brackets as wildcards, so the queue is matched by exact name.
# Jobs the spooler will not let go of (often stuck at Deleting) need the
# print spooler restarted; the script says so and exits 1.

$printerName = "$env:RMM_PrinterName"

if (-not $printerName.Trim()) {
    Write-Output 'ATTENTION: No printer name was given. Set the Printer Name parameter'
    exit 1
}

if ($printerName -match '[\x00-\x1f]') {
    Write-Output 'ATTENTION: The printer name contains control characters'
    exit 1
}

if ((Get-Service -Name 'Spooler').Status -ne 'Running') {
    Write-Output 'ATTENTION: The print spooler is not running. Restart the print spooler first'
    exit 1
}

$printer = Get-Printer -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq $printerName } | Select-Object -First 1

if ($null -eq $printer) {
    Write-Output "ATTENTION: There is no printer named '$printerName' on this PC"
    exit 1
}

$jobs = @(Get-PrintJob -PrinterObject $printer -ErrorAction SilentlyContinue)

if ($jobs.Count -eq 0) {
    Write-Output "OK: '$printerName' has no jobs queued"
    exit 0
}

Write-Output "Removing $($jobs.Count) job(s) from '$printerName'"
$jobs | Remove-PrintJob -ErrorAction SilentlyContinue

foreach ($attempt in 1..10) {
    Start-Sleep -Seconds 1
    $left = @(Get-PrintJob -PrinterObject $printer -ErrorAction SilentlyContinue)
    if ($left.Count -eq 0) {
        Write-Output "OK: '$printerName' queue cleared"
        exit 0
    }
}

Write-Output "ATTENTION: $($left.Count) job(s) are still queued on '$printerName'. Restart the print spooler to release them"
$left | ForEach-Object { Write-Output "  Job $($_.Id): $($_.DocumentName) ($($_.JobStatus))" }
exit 1
