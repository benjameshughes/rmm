# Adds a printer shared by the CUPS server, machine-wide, idempotently.
#
# Why a local queue and not a connection: Add-Printer -ConnectionName and
# printui /in make per-user connections, which SYSTEM would only add to its
# own profile, and printui /ga only takes \\server\share. Add-PrinterPort has
# no IPP option, and CUPS does not listen on 9100 or 515 by default, so a
# TCP/IP port would need the server changed. A local queue whose port is the
# IPP URL belongs to the PC, so every user sees it, including users who sign
# in later.
#
# Windows 11 22H2 and later have Add-Printer -IppURL, which makes that queue
# on an IPP port with the Microsoft IPP Class Driver and works under Windows
# protected print mode. Older builds take the same URL as -PortName with the
# class driver. A different Driver must already be installed on the PC.
#
# Safe on a schedule: a printer already on this queue with the right name
# and driver is left alone. One with the wanted name on another port is
# replaced. The result is checked with Get-Printer and its port and driver
# reported.

$driverName = "$env:RMM_Driver".Trim()
$classDriver = 'Microsoft IPP Class Driver'

if (-not $driverName) {
    $driverName = $classDriver
}

if ($driverName -notmatch '^[A-Za-z0-9 _.()&+,/-]{1,200}$') {
    Write-Output 'ATTENTION: The driver name may only use letters, digits, spaces and _ . ( ) & + , / -'
    exit 1
}

if ($null -eq (Get-PrinterDriver -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq $driverName } | Select-Object -First 1) -and $driverName -ne $classDriver) {
    Write-Output "ATTENTION: The driver '$driverName' is not installed on this PC. Install it first or leave Driver blank for the $classDriver"
    exit 1
}

$existing = Get-CupsPrinters
$onQueue = @($existing | Where-Object { Test-CupsPort "$($_.PortName)" })
$wrongPort = @($existing | Where-Object { -not (Test-CupsPort "$($_.PortName)") })

foreach ($printer in $wrongPort) {
    Write-Output "Replacing '$($printer.Name)', which is on port $($printer.PortName)"
    Remove-Printer -Name $printer.Name -ErrorAction SilentlyContinue
}

foreach ($printer in @($onQueue | Select-Object -Skip 1)) {
    Write-Output "Removing duplicate '$($printer.Name)' on the same queue"
    Remove-Printer -Name $printer.Name -ErrorAction SilentlyContinue
}

$printer = $onQueue | Select-Object -First 1

if ($null -eq $printer) {
    Write-Output "Adding $cupsUrl"
    $before = @(Get-Printer -ErrorAction SilentlyContinue | ForEach-Object { $_.Name })

    if ((Get-Command Add-Printer).Parameters.ContainsKey('IppURL')) {
        Add-Printer -IppURL $cupsUrl -ErrorAction SilentlyContinue -ErrorVariable addErrors
    } else {
        Add-Printer -Name $cupsDisplayName -PortName $cupsUrl -DriverName $classDriver -ErrorAction SilentlyContinue -ErrorVariable addErrors
    }

    $printer = Get-CupsPrinters | Where-Object { $before -notcontains $_.Name } | Select-Object -First 1

    if ($null -eq $printer) {
        $printer = Get-CupsPrinters | Select-Object -First 1
    }

    if ($null -eq $printer) {
        Write-Output "ATTENTION: Windows could not add $cupsUrl. Check the queue is shared on the CUPS server and reachable from this PC on port $cupsPort"
        $addErrors | ForEach-Object { Write-Output "  $_" }
        exit 1
    }
}

if ($printer.Name -ne $cupsDisplayName) {
    Write-Output "Renaming '$($printer.Name)' to '$cupsDisplayName'"
    Rename-Printer -Name $printer.Name -NewName $cupsDisplayName -ErrorAction SilentlyContinue
}

if ($printer.DriverName -ne $driverName) {
    Write-Output "Setting the driver to '$driverName'"
    Set-Printer -Name $cupsDisplayName -DriverName $driverName -ErrorAction SilentlyContinue
}

$result = Get-Printer -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq $cupsDisplayName } | Select-Object -First 1

if ($null -eq $result) {
    Write-Output "ATTENTION: '$cupsDisplayName' is not on this PC after adding it"
    exit 1
}

if ($result.DriverName -ne $driverName) {
    Write-Output "ATTENTION: '$cupsDisplayName' is on $($result.PortName) but uses the driver '$($result.DriverName)', not '$driverName'"
    exit 1
}

Write-Output "OK: '$cupsDisplayName' is on port $($result.PortName) with the driver '$($result.DriverName)', for every user"
exit 0
