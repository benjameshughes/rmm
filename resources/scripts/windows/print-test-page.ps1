# Prints the Windows test page on one printer. The printer name arrives as
# the RMM_PrinterName environment variable, so the value never becomes script
# text. Printer names hold spaces, '#' and brackets, so the printer is
# matched by exact name rather than through a WMI filter or wildcard.

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

$printer = Get-CimInstance -ClassName Win32_Printer -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq $printerName } | Select-Object -First 1

if ($null -eq $printer) {
    Write-Output "ATTENTION: There is no printer named '$printerName' on this PC"
    exit 1
}

$result = Invoke-CimMethod -InputObject $printer -MethodName PrintTestPage

if ($null -eq $result -or $result.ReturnValue -ne 0) {
    Write-Output "ATTENTION: Windows could not print a test page on '$printerName' (return value $($result.ReturnValue))"
    exit 1
}

Write-Output "OK: Test page sent to '$printerName'"
exit 0
