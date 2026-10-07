# Restarts the Windows print spooler, which releases jobs stuck at Deleting
# and brings back a spooler that stopped or hung. A spooler that will not
# stop within 20 seconds is killed. Waits up to 30 seconds for it to run.

$before = (Get-Service -Name 'Spooler').Status
Write-Output "Print spooler was $before; restarting it"

Stop-Service -Name 'Spooler' -Force -NoWait -ErrorAction SilentlyContinue

foreach ($attempt in 1..20) {
    if ((Get-Service -Name 'Spooler').Status -eq 'Stopped') {
        break
    }
    Start-Sleep -Seconds 1
}

if ((Get-Service -Name 'Spooler').Status -ne 'Stopped') {
    Write-Output 'Print spooler did not stop within 20 seconds; killing spoolsv.exe'
    Stop-Process -Name 'spoolsv' -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
}

Start-Service -Name 'Spooler' -ErrorAction SilentlyContinue

foreach ($attempt in 1..15) {
    Start-Sleep -Seconds 2
    $status = (Get-Service -Name 'Spooler').Status
    if ($status -eq 'Running') {
        Write-Output 'OK: Print spooler is running'
        exit 0
    }
    if ($status -eq 'Stopped') {
        Start-Service -Name 'Spooler' -ErrorAction SilentlyContinue
    }
}

Write-Output "ATTENTION: Print spooler is $((Get-Service -Name 'Spooler').Status) after 30 seconds"
exit 1
