# Shared CUPS printer setup, put in front of add-cups-printer and
# remove-cups-printer by the script sync. Reads and checks the parameters,
# builds the queue's IPP URL and finds the PC's printers already on it.
#
# Parameters arrive as RMM_* environment variables, so no value ever becomes
# script text, and each is checked strictly before it reaches a cmdlet. CUPS
# queue names are letters, digits, '_', '-' and '.', no spaces or slashes.
#
# Windows PowerShell 5.1, ASCII only, no try/catch.

$cupsServer = "$env:RMM_Server".Trim()
$cupsPort = "$env:RMM_Port".Trim()
$cupsQueue = "$env:RMM_Printer".Trim()
$cupsDisplayName = "$env:RMM_DisplayName".Trim()

if ($cupsServer -notmatch '^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9-]{0,62})(\.[A-Za-z0-9]([A-Za-z0-9-]{0,62}))*$') {
    Write-Output 'ATTENTION: The CUPS server must be a host name or IPv4 address, e.g. 10.0.60.218'
    exit 1
}

if ($cupsPort -notmatch '^[0-9]{1,5}$' -or [int] $cupsPort -lt 1 -or [int] $cupsPort -gt 65535) {
    Write-Output 'ATTENTION: The IPP port must be a number from 1 to 65535'
    exit 1
}

if ($cupsQueue -notmatch '^[A-Za-z0-9][A-Za-z0-9_.-]{0,126}$') {
    Write-Output 'ATTENTION: The CUPS queue name must be letters, digits, _ - or . (no spaces), as shown on the CUPS Printers page'
    exit 1
}

if (-not $cupsDisplayName) {
    $cupsDisplayName = $cupsQueue
}

if ($cupsDisplayName -notmatch '^[A-Za-z0-9 _.()&+-]{1,100}$') {
    Write-Output 'ATTENTION: The name on the PC may only use letters, digits, spaces and _ . ( ) & + - (100 at most)'
    exit 1
}

if ((Get-Service -Name 'Spooler').Status -ne 'Running') {
    Write-Output 'ATTENTION: The print spooler is not running. Restart the print spooler first'
    exit 1
}

$cupsUrl = "http://${cupsServer}:$cupsPort/printers/$cupsQueue"

# True when the port is this CUPS queue, by any URL scheme or case. Not
# anchored at the start, since the port -IppURL makes may wrap the URL.
function Test-CupsPort([string] $portName) {
    $portName.TrimEnd('/') -match ('(https?|ipps?)://' + [regex]::Escape("${cupsServer}:$cupsPort/printers/$cupsQueue") + '$')
}

# The PC's printers on this CUPS queue, or named as wanted. Matched by
# exact name, since Get-Printer -Name treats brackets as wildcards.
function Get-CupsPrinters {
    @(Get-Printer -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq $cupsDisplayName -or (Test-CupsPort "$($_.PortName)") })
}
