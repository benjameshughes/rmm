# Sets the PC's USB Zebra label printer up on Zebra's own ZDesigner driver,
# the same way on every PC, and safe to run again:
#
# 1. Installs the pinned ZDesigner driver unless it is already there: the
#    self-hosted zip is downloaded into the locked RMM folder, checked against
#    its sha256, staged with pnputil and added as a printer driver.
# 2. Finds the Zebra's USB port from an existing Zebra queue, or has Plug and
#    Play rescan when none has one yet.
# 3. Keeps one queue named after the driver (the name Windows itself gives
#    it) on that port, renaming or creating it as needed, and removes every
#    other Zebra queue: the Seagull ones, "#2", "(Copy 1)". Zebra drivers left
#    unused (Seagull's) are removed after, best effort, so Plug and Play
#    cannot bring their queues back.
# 4. Restores the Zebra template (printui /Sr, the printer's default
#    preferences and printer data) when one has been captured on the same
#    driver.
# 5. Makes the queue the default printer for every user profile on the PC and
#    the Default profile new users start from, with Windows' own default
#    printer management turned off so it stays put.
# 6. Prints a Windows test page only when TestPage is set.
#
# The driver pin, match patterns and the template arrive as RMM_* secrets
# only when the agent fetches the command. Ends with one JSON line.

$driverUrl = "$env:RMM_ZebraDriverUrl".Trim()
$driverSha256 = "$env:RMM_ZebraDriverSha256".Trim().ToLowerInvariant()
$driverInf = "$env:RMM_ZebraDriverInf".Trim()
$driverName = "$env:RMM_ZebraDriverName".Trim()
$matchPattern = "$env:RMM_ZebraMatch".Trim()
$templateB64 = "$env:RMM_ZebraTemplate".Trim()
$templateDriver = "$env:RMM_ZebraTemplateDriver".Trim()
$isTestPageWanted = @('1', 'true') -contains "$env:RMM_TestPage".Trim().ToLowerInvariant()
$pnpWaitSeconds = 0
$problems = New-Object System.Collections.Generic.List[string]

if (-not $driverUrl -or $driverSha256 -notmatch '^[0-9a-f]{64}$' -or -not $driverInf -or -not $driverName -or -not $matchPattern -or -not $zebraDriverPrefix) {
    Write-Output 'ATTENTION: the server did not send the ZDesigner driver URL and sha256 pin. Set ZEBRA_DRIVER_URL and ZEBRA_DRIVER_SHA256 (config/zebra.php) and update the agent'
    Write-Output (ConvertTo-Json -InputObject @{ status = 'failed'; exit_code = 1; errors = @('driver pin missing') } -Compress)
    exit 1
}

if (-not [int]::TryParse("$env:RMM_ZebraPnpWaitSeconds", [ref]$pnpWaitSeconds) -or $pnpWaitSeconds -lt 0) {
    $pnpWaitSeconds = 60
}

if ((Get-Service -Name 'Spooler').Status -ne 'Running') {
    Stop-Run 'The print spooler is not running. Restart the print spooler first'
}

$pnputil = if (Test-Path -LiteralPath "$env:SystemRoot\Sysnative\pnputil.exe") { "$env:SystemRoot\Sysnative\pnputil.exe" } else { "$env:SystemRoot\System32\pnputil.exe" }

function Get-LocalPrinters {
    return @(Get-Printer -ErrorAction SilentlyContinue | Where-Object { "$($_.Type)" -ne 'Connection' })
}

function Test-ZebraQueue($printer) {
    return "$($printer.Name)" -match $matchPattern -or "$($printer.DriverName)" -match $matchPattern
}

function Get-ZebraDriver {
    return Get-PrinterDriver -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq $driverName } | Select-Object -First 1
}

# The USB port of a Zebra queue, preferring one already on the ZDesigner driver.
function Find-ZebraUsbPort {
    $usbQueues = @(Get-LocalPrinters | Where-Object { (Test-ZebraQueue $_) -and $_.PortName -like 'USB*' })
    $preferred = @($usbQueues | Where-Object { $_.DriverName -eq $driverName }) + $usbQueues

    return ($preferred | Select-Object -First 1).PortName
}

# 1. The driver.
$driverWasInstalled = $false

if ($null -eq (Get-ZebraDriver)) {
    Use-ZebraFolder

    $zip = Join-Path $zebraDir 'driver.zip'
    $extractDir = Join-Path $zebraDir 'driver'
    Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $extractDir -Recurse -Force -ErrorAction SilentlyContinue

    foreach ($attempt in 1..3) {
        Invoke-WebRequest -Uri $driverUrl -OutFile $zip -UseBasicParsing -ErrorAction SilentlyContinue
        if ((Test-Path -LiteralPath $zip) -and (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant() -eq $driverSha256) {
            break
        }
        Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds (5 * $attempt)
    }

    if (-not (Test-Path -LiteralPath $zip)) {
        Stop-Run "could not download the ZDesigner driver with the pinned sha256 after 3 attempts; nothing was changed"
    }

    Expand-Archive -LiteralPath $zip -DestinationPath $extractDir -Force
    $infPath = Join-Path $extractDir ($driverInf -replace '/', '\')

    if (-not (Test-Path -LiteralPath $infPath -PathType Leaf)) {
        Stop-Run "the driver zip has no $driverInf. Check ZEBRA_DRIVER_INF in config/zebra.php"
    }

    Write-Output "Staging $driverInf with pnputil"
    $pnputilOutput = & $pnputil /add-driver $infPath /install 2>&1 | Out-String
    Write-Output $pnputilOutput.Trim()

    Add-PrinterDriver -Name $driverName -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $extractDir -Recurse -Force -ErrorAction SilentlyContinue

    if ($null -eq (Get-ZebraDriver)) {
        Stop-Run "the driver package was staged but Windows has no '$driverName' printer driver. Check ZEBRA_DRIVER_NAME matches a model in $driverInf"
    }

    $driverWasInstalled = $true
    Write-Output "OK: installed the $driverName driver"
}
else {
    Write-Output "OK: the $driverName driver is already installed"
}

# 2. The USB port.
$port = Find-ZebraUsbPort

if (-not $port) {
    $pnpZebra = @(Get-PnpDevice -PresentOnly -ErrorAction SilentlyContinue | Where-Object { $_.InstanceId -like 'USBPRINT\*' -and ("$($_.FriendlyName)" -match $matchPattern -or $_.InstanceId -match $matchPattern) })

    if ($pnpZebra.Count -gt 0) {
        Write-Output "No Zebra queue holds a USB port yet; asking Plug and Play to rescan"
        & $pnputil /scan-devices 2>&1 | Out-Null
        $deadline = (Get-Date).AddSeconds($pnpWaitSeconds)

        while (-not $port -and (Get-Date) -lt $deadline) {
            Start-Sleep -Seconds 5
            $port = Find-ZebraUsbPort
        }
    }
}

if (-not $port) {
    Stop-Run 'No USB Zebra found on this PC: no Zebra queue is on a USB port and Plug and Play did not make one. Plug the Zebra in, switch it on and run again'
}

# 3. One queue on the driver, named after it.
$queueName = $driverName
$queue = Get-LocalPrinters | Where-Object { $_.Name -eq $queueName } | Select-Object -First 1

if ($null -ne $queue) {
    if ($queue.DriverName -ne $driverName -or $queue.PortName -ne $port) {
        Write-Output "Moving '$queueName' onto $driverName on $port"
        $queue | Set-Printer -DriverName $driverName -PortName $port -ErrorAction SilentlyContinue
    }
}
else {
    $renameable = Get-LocalPrinters | Where-Object { $_.DriverName -eq $driverName -and $_.PortName -eq $port } | Select-Object -First 1

    if ($null -ne $renameable) {
        Write-Output "Renaming '$($renameable.Name)' to '$queueName'"
        $renameable | Rename-Printer -NewName $queueName -ErrorAction SilentlyContinue
    }
    else {
        Write-Output "Adding '$queueName' on $port"
        Add-Printer -Name $queueName -DriverName $driverName -PortName $port -ErrorAction SilentlyContinue
    }
}

$queue = Get-LocalPrinters | Where-Object { $_.Name -eq $queueName -and $_.DriverName -eq $driverName -and $_.PortName -eq $port } | Select-Object -First 1

if ($null -eq $queue) {
    Stop-Run "could not set up '$queueName' on $driverName on $port"
}

Write-Output "OK: '$queueName' is on $driverName on $port"

# Every other Zebra queue goes, never the one just set up.
$removedQueues = New-Object System.Collections.Generic.List[string]

Get-LocalPrinters | Where-Object { (Test-ZebraQueue $_) -and $_.Name -ne $queueName } | ForEach-Object {
    $oldQueue = $_
    Get-PrintJob -PrinterObject $oldQueue -ErrorAction SilentlyContinue | Remove-PrintJob -ErrorAction SilentlyContinue
    Remove-Printer -InputObject $oldQueue -ErrorAction SilentlyContinue

    if (Get-LocalPrinters | Where-Object { $_.Name -eq $oldQueue.Name }) {
        $problems.Add("could not remove the old queue '$($oldQueue.Name)'")
    }
    else {
        $removedQueues.Add($oldQueue.Name)
        Write-Output "OK: removed the old queue '$($oldQueue.Name)' ($($oldQueue.DriverName))"
    }
}

# Zebra drivers no queue uses any more, other than ZDesigner ones, best effort.
$removedDrivers = New-Object System.Collections.Generic.List[string]
$driversInUse = @(Get-Printer -ErrorAction SilentlyContinue | ForEach-Object { $_.DriverName })

Get-PrinterDriver -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match $matchPattern -and -not (Test-ZDesignerDriver $_.Name) -and $driversInUse -notcontains $_.Name } |
    ForEach-Object {
        $oldDriver = $_
        Remove-PrinterDriver -InputObject $oldDriver -RemoveFromDriverStore -ErrorAction SilentlyContinue

        if (Get-PrinterDriver -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq $oldDriver.Name }) {
            Remove-PrinterDriver -InputObject $oldDriver -ErrorAction SilentlyContinue
        }

        if (Get-PrinterDriver -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq $oldDriver.Name }) {
            Write-Output "Left the unused driver '$($oldDriver.Name)' in place; Windows would not remove it"
        }
        else {
            $removedDrivers.Add($oldDriver.Name)
            Write-Output "OK: removed the unused driver '$($oldDriver.Name)'"
        }
    }

# 4. The template.
$isTemplateApplied = $false

if (-not $templateB64) {
    Write-Output 'OK: no Zebra template has been captured yet; the driver defaults stay'
}
elseif ($templateDriver -ne $driverName) {
    $problems.Add("the Zebra template was captured on '$templateDriver', not '$driverName', so it was not applied. Capture it again from a $driverName printer")
}
elseif ($templateB64 -notmatch '^[A-Za-z0-9+/]+={0,2}$' -or $templateB64.Length % 4 -ne 0) {
    $problems.Add('the Zebra template from the server is not valid base64, so it was not applied')
}
else {
    Use-ZebraFolder

    $compressed = New-Object IO.MemoryStream(, [Convert]::FromBase64String($templateB64))
    $gzip = New-Object IO.Compression.GZipStream($compressed, [IO.Compression.CompressionMode]::Decompress)
    $settings = New-Object IO.MemoryStream
    $gzip.CopyTo($settings)
    $gzip.Close()

    $templateFile = Join-Path $zebraDir 'template.dat'
    [IO.File]::WriteAllBytes($templateFile, $settings.ToArray())
    $restoreExitCode = Invoke-PrintUi @('/Sr', '/n', $queueName, '/a', $templateFile, '/q', 'g', 'd', 'r', 'p')
    Remove-Item -LiteralPath $templateFile -Force -ErrorAction SilentlyContinue

    if ($restoreExitCode -ne 0) {
        $problems.Add("printui could not restore the Zebra template onto '$queueName' (exit code $restoreExitCode)")
    }
    else {
        $isTemplateApplied = $true
        Write-Output "OK: applied the Zebra template to '$queueName'"
    }
}

# 5. Default printer for every profile and the Default profile.
$windowsKeyPath = 'Software\Microsoft\Windows NT\CurrentVersion\Windows'

function Set-DefaultZebra([string] $hiveRoot) {
    $windowsKey = "Registry::$hiveRoot\$windowsKeyPath"
    $devicesKey = "Registry::$hiveRoot\Software\Microsoft\Windows NT\CurrentVersion\Devices"
    $portPart = (Get-ItemProperty -LiteralPath $devicesKey -ErrorAction SilentlyContinue).$queueName

    if ("$portPart" -notmatch '^winspool,') {
        $portPart = 'winspool,Ne00:'
    }

    if (-not (Test-Path -LiteralPath $windowsKey)) {
        New-Item -Path $windowsKey -ErrorAction SilentlyContinue | Out-Null
    }

    Set-ItemProperty -LiteralPath $windowsKey -Name 'LegacyDefaultPrinterMode' -Value 1 -Type DWord -ErrorAction SilentlyContinue
    Set-ItemProperty -LiteralPath $windowsKey -Name 'Device' -Value "$queueName,$portPart" -Type String -ErrorAction SilentlyContinue

    return (Get-ItemProperty -LiteralPath $windowsKey -ErrorAction SilentlyContinue).Device -eq "$queueName,$portPart"
}

# Loads an offline profile's NTUSER.DAT, sets the default, unloads it.
function Set-DefaultZebraOffline([string] $hivePath, [string] $mountName) {
    if (-not (Test-Path -LiteralPath $hivePath -PathType Leaf)) {
        return $false
    }

    & reg.exe load "HKU\$mountName" $hivePath 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        return $false
    }

    $isSet = Set-DefaultZebra "HKEY_USERS\$mountName"
    [gc]::Collect()
    [gc]::WaitForPendingFinalizers()
    & reg.exe unload "HKU\$mountName" 2>&1 | Out-Null

    return $isSet
}

$profileListPath = 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList'
$loadedSids = @(Get-ChildItem -Path 'Registry::HKEY_USERS' | ForEach-Object { $_.PSChildName } | Where-Object { $_ -match '^S-1-5-21-[\d-]+$' })
$profilesSet = 0
$profilesMissed = New-Object System.Collections.Generic.List[string]

$loadedSids | ForEach-Object {
    if (Set-DefaultZebra "HKEY_USERS\$_") { $profilesSet++ } else { $profilesMissed.Add($_) }
}

Get-ChildItem -Path $profileListPath | Where-Object { $_.PSChildName -match '^S-1-5-21-[\d-]+$' -and $loadedSids -notcontains $_.PSChildName } | ForEach-Object {
    $profilePath = [Environment]::ExpandEnvironmentVariables("$((Get-ItemProperty -LiteralPath $_.PSPath).ProfileImagePath)")
    if (Set-DefaultZebraOffline (Join-Path $profilePath 'NTUSER.DAT') 'RMM_Zebra_Profile') { $profilesSet++ } else { $profilesMissed.Add($profilePath) }
}

$defaultProfile = [Environment]::ExpandEnvironmentVariables("$((Get-ItemProperty -LiteralPath $profileListPath).Default)")
$isDefaultProfileSet = Set-DefaultZebraOffline (Join-Path $defaultProfile 'NTUSER.DAT') 'RMM_Zebra_Default'

Write-Output "OK: '$queueName' is the default printer for $profilesSet user $(if ($profilesSet -eq 1) { 'profile' } else { 'profiles' })$(if ($isDefaultProfileSet) { ' and the Default profile' })"

if ($profilesMissed.Count -gt 0) {
    Write-Output "Could not set the default printer for: $($profilesMissed -join ', ') (profile in use or missing)"
}

# 6. Test page, only when asked.
$isTestPageSent = $false

if ($isTestPageWanted) {
    $cimPrinter = Get-CimInstance -ClassName Win32_Printer -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq $queueName } | Select-Object -First 1
    $testResult = if ($null -ne $cimPrinter) { Invoke-CimMethod -InputObject $cimPrinter -MethodName PrintTestPage } else { $null }
    $isTestPageSent = $null -ne $testResult -and $testResult.ReturnValue -eq 0

    if ($isTestPageSent) {
        Write-Output "OK: test page sent to '$queueName'"
    }
    else {
        $problems.Add("Windows could not print a test page on '$queueName'")
    }
}

$problems | ForEach-Object { Write-Output "ATTENTION: $_" }
Write-Output "OK: Zebra installed as '$queueName'"

$exitCode = if ($problems.Count -gt 0) { 1 } else { 0 }
Write-Output (ConvertTo-Json -Compress -InputObject @{
        status = if ($exitCode -eq 0) { 'ok' } else { 'attention' }
        exit_code = $exitCode
        printer_name = $queueName
        driver_name = $driverName
        port_name = $port
        driver_installed = $driverWasInstalled
        removed_queues = @($removedQueues)
        removed_drivers = @($removedDrivers)
        template_applied = $isTemplateApplied
        default_profiles = $profilesSet
        default_profile_template = $isDefaultProfileSet
        test_page = $isTestPageSent
        errors = @($problems)
    })

if ($exitCode -ne 0) {
    exit 1
}

exit 0
