# Sets the PC's Zebra GK420d up for 80 x 40 mm gap labels torn off at the
# bar, by sending ZPL straight to the printer (RAW, past the driver):
#
#   ^MNY   gap/notch media sensing      ^MTD   direct thermal, no ribbon
#   ^MMT   tear off after each label    ^PW640 / ^LL320  80 x 40 mm at 203 dpi
#   ^JUS   save it to the printer       ~JC    calibrate the gap sensor
#
# The printer feeds a few labels while it calibrates. Ends with one JSON line.
#
# Windows PowerShell 5.1, ASCII only, no try/catch.

$matchPattern = 'Zebra|ZDesigner|GK420'
$zpl = "^XA^MNY^MTD^MMT^PW640^LL320^LH0,0^JUS^XZ~JC^XA^JUS^XZ"

Add-Type -Namespace RmmZebra -Name Native -MemberDefinition @'
[StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
public struct DocInfo1 { public string pDocName; public string pOutputFile; public string pDatatype; }

[DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
public static extern bool OpenPrinter(string pPrinterName, out IntPtr phPrinter, IntPtr pDefault);

[DllImport("winspool.drv", SetLastError = true)]
public static extern bool ClosePrinter(IntPtr hPrinter);

[DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
public static extern int StartDocPrinter(IntPtr hPrinter, int Level, ref DocInfo1 pDocInfo);

[DllImport("winspool.drv", SetLastError = true)]
public static extern bool EndDocPrinter(IntPtr hPrinter);

[DllImport("winspool.drv", SetLastError = true)]
public static extern bool StartPagePrinter(IntPtr hPrinter);

[DllImport("winspool.drv", SetLastError = true)]
public static extern bool EndPagePrinter(IntPtr hPrinter);

[DllImport("winspool.drv", SetLastError = true)]
public static extern bool WritePrinter(IntPtr hPrinter, byte[] pBytes, int dwCount, out int dwWritten);
'@

function Stop-Run([string] $message) {
    Write-Output "ATTENTION: $message"
    Write-Output (ConvertTo-Json -InputObject @{ status = 'failed'; exit_code = 1; errors = @($message) } -Compress)
    exit 1
}

# Sends the bytes as one RAW job. Returns why it failed, or nothing.
function Send-Raw([string] $printerName, [byte[]] $bytes) {
    $handle = [IntPtr]::Zero
    if (-not [RmmZebra.Native]::OpenPrinter($printerName, [ref] $handle, [IntPtr]::Zero)) {
        return "could not open '$printerName' (Win32 error $([Runtime.InteropServices.Marshal]::GetLastWin32Error()))"
    }

    $doc = New-Object RmmZebra.Native+DocInfo1
    $doc.pDocName = 'RMM Zebra label setup'
    $doc.pDatatype = 'RAW'
    $written = 0

    $isSent = [RmmZebra.Native]::StartDocPrinter($handle, 1, [ref] $doc) -ne 0 -and
        [RmmZebra.Native]::StartPagePrinter($handle) -and
        [RmmZebra.Native]::WritePrinter($handle, $bytes, $bytes.Length, [ref] $written)
    $lastError = [Runtime.InteropServices.Marshal]::GetLastWin32Error()

    [RmmZebra.Native]::EndPagePrinter($handle) | Out-Null
    [RmmZebra.Native]::EndDocPrinter($handle) | Out-Null
    [RmmZebra.Native]::ClosePrinter($handle) | Out-Null

    if (-not $isSent -or $written -ne $bytes.Length) {
        return "could not send the setup to '$printerName' (Win32 error $lastError)"
    }
}

if ((Get-Service -Name 'Spooler').Status -ne 'Running') {
    Stop-Run 'The print spooler is not running. Restart the print spooler first'
}

$zebras = @(Get-Printer -ErrorAction SilentlyContinue | Where-Object {
    "$($_.Type)" -ne 'Connection' -and ("$($_.Name)" -match $matchPattern -or "$($_.DriverName)" -match $matchPattern)
})

if ($zebras.Count -eq 0) {
    Stop-Run 'No Zebra printer queue on this PC. Run Install Zebra first'
}

$bytes = [Text.Encoding]::ASCII.GetBytes($zpl)
$sent = New-Object System.Collections.Generic.List[string]
$problems = New-Object System.Collections.Generic.List[string]

$zebras | ForEach-Object {
    $problem = Send-Raw $_.Name $bytes
    if ($problem) {
        $problems.Add($problem)
    }
    else {
        $sent.Add($_.Name)
        Write-Output "OK: sent the 80 x 40 mm gap, tear-off setup and calibration to '$($_.Name)'"
    }
}

if ($sent.Count -eq 0) {
    Stop-Run ($problems -join '; ')
}

$problems | ForEach-Object { Write-Output "ATTENTION: $_" }
Write-Output 'The printer feeds a few labels while it calibrates; tear them off'
Write-Output (ConvertTo-Json -InputObject @{ status = 'success'; exit_code = 0; printers = @($sent); errors = @($problems) } -Compress)
exit 0
