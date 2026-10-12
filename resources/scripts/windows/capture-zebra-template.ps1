# Saves one ZDesigner printer's settings as the Zebra template. printui /Ss
# stores the printer's default printing preferences (g: the global DEVMODE,
# paper size, darkness, speed, media type) and its printer data (d) to a file
# in the locked RMM folder. The file is gzipped and base64 encoded into the
# final JSON line, which the RMM keeps as the one template install-zebra
# restores on every PC. Only a queue on the ZDesigner driver is captured:
# its settings restore onto no other driver, Seagull's included.
#
# The printer name arrives as RMM_PrinterName and is matched exactly. The
# template must fit in one environment variable on the way back
# (RMM_ZebraTemplateMaxChars). Changes no printer.

$printerName = "$env:RMM_PrinterName"
$maxChars = 0

if (-not $printerName.Trim()) {
    Write-Output 'ATTENTION: No printer name was given. Set the Printer Name parameter'
    exit 1
}

if ($printerName -match '[\x00-\x1f"]') {
    Write-Output 'ATTENTION: The printer name contains control characters or quotes'
    exit 1
}

if (-not $zebraDriverPrefix -or -not [int]::TryParse("$env:RMM_ZebraTemplateMaxChars", [ref]$maxChars) -or $maxChars -lt 1) {
    Stop-Run 'the server did not send the ZDesigner driver prefix and template size limit. Update the agent and check config/zebra.php'
}

$printer = Get-Printer -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq $printerName } | Select-Object -First 1

if ($null -eq $printer) {
    Stop-Run "There is no printer named '$printerName' on this PC"
}

if (-not (Test-ZDesignerDriver $printer.DriverName)) {
    Stop-Run "'$printerName' uses the '$($printer.DriverName)' driver. Only a printer on the $zebraDriverPrefix driver can be the Zebra template; run Install Zebra on this PC first"
}

Use-ZebraFolder

$settingsFile = Join-Path $zebraDir 'capture.dat'
Remove-Item -LiteralPath $settingsFile -Force -ErrorAction SilentlyContinue

$exitCode = Invoke-PrintUi @('/Ss', '/n', $printerName, '/a', $settingsFile, '/q', 'g', 'd')

if (-not (Test-Path -LiteralPath $settingsFile) -or (Get-Item -LiteralPath $settingsFile).Length -eq 0) {
    Stop-Run "printui could not save the settings of '$printerName' (exit code $exitCode)"
}

$bytes = [IO.File]::ReadAllBytes($settingsFile)
Remove-Item -LiteralPath $settingsFile -Force -ErrorAction SilentlyContinue

$buffer = New-Object IO.MemoryStream
$gzip = New-Object IO.Compression.GZipStream($buffer, [IO.Compression.CompressionMode]::Compress)
$gzip.Write($bytes, 0, $bytes.Length)
$gzip.Close()
$settingsB64 = [Convert]::ToBase64String($buffer.ToArray())

if ($settingsB64.Length -gt $maxChars) {
    Stop-Run "the settings of '$printerName' come to $($settingsB64.Length) characters, over the $maxChars a template may be"
}

Write-Output "OK: saved the settings of '$printerName' ($($printer.DriverName), $($bytes.Length) bytes) as the Zebra template"
Write-Output (ConvertTo-Json -Compress -InputObject @{
        status = 'ok'
        exit_code = 0
        printer_name = $printer.Name
        driver_name = $printer.DriverName
        port_name = $printer.PortName
        settings_b64 = $settingsB64
        captured_at = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    })
exit 0
