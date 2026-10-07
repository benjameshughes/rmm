# Removes one job from one print queue. The printer name and job ID arrive
# as the RMM_PrinterName and RMM_JobId environment variables, so neither
# value becomes script text. Printer names hold spaces, '#' and brackets,
# and Get-Printer -Name treats brackets as wildcards, so the queue is matched
# by exact name. A job that is already gone is not a failure.

$printerName = "$env:RMM_PrinterName"
$jobId = "$env:RMM_JobId".Trim()

if (-not $printerName.Trim()) {
    Write-Output 'ATTENTION: No printer name was given. Set the Printer Name parameter'
    exit 1
}

if ($printerName -match '[\x00-\x1f]') {
    Write-Output 'ATTENTION: The printer name contains control characters'
    exit 1
}

if ($jobId -notmatch '^\d{1,10}$') {
    Write-Output "ATTENTION: '$jobId' is not a valid job ID. Use the job number from the Printers tab"
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

$job = Get-PrintJob -PrinterObject $printer -ID ([uint32] $jobId) -ErrorAction SilentlyContinue

if ($null -eq $job) {
    Write-Output "OK: Job $jobId is no longer queued on '$printerName'"
    exit 0
}

Write-Output "Removing job ${jobId}: $($job.DocumentName) ($($job.JobStatus))"
$job | Remove-PrintJob -ErrorAction SilentlyContinue

foreach ($attempt in 1..10) {
    Start-Sleep -Seconds 1
    if ($null -eq (Get-PrintJob -PrinterObject $printer -ID ([uint32] $jobId) -ErrorAction SilentlyContinue)) {
        Write-Output "OK: Job $jobId removed from '$printerName'"
        exit 0
    }
}

Write-Output "ATTENTION: Job $jobId is still queued on '$printerName'. Restart the print spooler to release it"
exit 1
