# Adds the 80 x 40 mm label paper size to Windows as a print server form, so
# every printer and app on the PC can pick it (Zebra GK420d included). Safe
# to run again: an existing form of the same name is updated to the size.
#
# Windows PowerShell 5.1, ASCII only, no try/catch. Ends with one JSON line.

$formName = 'Label 80 x 40 mm'
$widthMm = 80
$heightMm = 40

Add-Type -Namespace RmmPrint -Name Native -MemberDefinition @'
[StructLayout(LayoutKind.Sequential)]
public struct PrinterDefaults { public IntPtr pDatatype; public IntPtr pDevMode; public int DesiredAccess; }

[StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
public struct FormInfo1 {
    public uint Flags;
    public string pName;
    public int Width; public int Height;
    public int Left; public int Top; public int Right; public int Bottom;
}

[DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
public static extern bool OpenPrinter(string pPrinterName, out IntPtr phPrinter, ref PrinterDefaults pDefault);

[DllImport("winspool.drv", SetLastError = true)]
public static extern bool ClosePrinter(IntPtr hPrinter);

[DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
public static extern bool AddForm(IntPtr hPrinter, int Level, ref FormInfo1 pForm);

[DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
public static extern bool SetForm(IntPtr hPrinter, string pFormName, int Level, ref FormInfo1 pForm);
'@

function Stop-Run([string] $message) {
    Write-Output "ATTENTION: $message"
    Write-Output (ConvertTo-Json -InputObject @{ status = 'failed'; exit_code = 1; errors = @($message) } -Compress)
    exit 1
}

if ((Get-Service -Name 'Spooler').Status -ne 'Running') {
    Stop-Run 'The print spooler is not running. Restart the print spooler first'
}

# Form sizes are in thousandths of a millimetre; the whole label is printable.
$form = New-Object RmmPrint.Native+FormInfo1
$form.Flags = 0
$form.pName = $formName
$form.Width = $widthMm * 1000
$form.Height = $heightMm * 1000
$form.Right = $form.Width
$form.Bottom = $form.Height

$defaults = New-Object RmmPrint.Native+PrinterDefaults
$defaults.DesiredAccess = 1
$server = [IntPtr]::Zero

if (-not [RmmPrint.Native]::OpenPrinter($null, [ref] $server, [ref] $defaults)) {
    Stop-Run ("could not open the local print server: Win32 error {0}" -f [Runtime.InteropServices.Marshal]::GetLastWin32Error())
}

$verb = 'Added'
$isSaved = [RmmPrint.Native]::AddForm($server, 1, [ref] $form)
$lastError = [Runtime.InteropServices.Marshal]::GetLastWin32Error()

if (-not $isSaved -and $lastError -eq 80) {
    $verb = 'Updated'
    $isSaved = [RmmPrint.Native]::SetForm($server, $formName, 1, [ref] $form)
    $lastError = [Runtime.InteropServices.Marshal]::GetLastWin32Error()
}

[RmmPrint.Native]::ClosePrinter($server) | Out-Null

if (-not $isSaved) {
    Stop-Run ("could not save the '$formName' form: Win32 error {0}" -f $lastError)
}

Write-Output "OK: $verb the '$formName' paper size (${widthMm} x ${heightMm} mm)"
Write-Output (ConvertTo-Json -InputObject @{ status = 'success'; exit_code = 0; form = $formName; width_mm = $widthMm; height_mm = $heightMm } -Compress)
exit 0
