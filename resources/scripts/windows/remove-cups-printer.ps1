# Removes a printer added by Add CUPS Printer: every printer on the CUPS
# queue's IPP URL, and the one with the given name on the PC. Its port is
# removed too once nothing else uses it. Safe on a schedule: nothing there
# is a success.

$printers = Get-CupsPrinters

if ($printers.Count -eq 0) {
    Write-Output "OK: No printer for $cupsUrl or named '$cupsDisplayName' on this PC"
    exit 0
}

foreach ($printer in $printers) {
    Write-Output "Removing '$($printer.Name)' (port $($printer.PortName))"
    Remove-Printer -Name $printer.Name -ErrorAction SilentlyContinue
}

$ports = @($printers | ForEach-Object { "$($_.PortName)" } | Select-Object -Unique)
$inUse = @(Get-Printer -ErrorAction SilentlyContinue | ForEach-Object { "$($_.PortName)" })

foreach ($port in @($ports | Where-Object { (Test-CupsPort $_) -and $inUse -notcontains $_ })) {
    Remove-PrinterPort -Name $port -ErrorAction SilentlyContinue
}

$left = Get-CupsPrinters

if ($left.Count -gt 0) {
    Write-Output "ATTENTION: $($left.Count) printer(s) are still on this PC. Restart the print spooler and run this again"
    $left | ForEach-Object { Write-Output "  $($_.Name) (port $($_.PortName))" }
    exit 1
}

Write-Output "OK: Removed $($printers.Count) printer(s) for $cupsUrl"
exit 0
