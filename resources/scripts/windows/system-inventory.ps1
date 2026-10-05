# Read-only deep inventory of the PC: hardware, Windows, security, users,
# updates and what third parties have added (drivers, startup items, services,
# scheduled tasks). Prints ONE line of compact JSON for the server to store.
#
# Runs as SYSTEM under Windows PowerShell 5.1 and never changes anything. Every
# section reads with errors silenced and null checks, so a class missing on
# this edition leaves its section null or empty instead of failing the run.
# Never outputs a product key or a BitLocker recovery secret.
#
# PowerShell 5.1 notes: ConvertTo-Json writes DateTime as \/Date()\/, so dates
# are formatted as ISO-8601 strings first. Functions unroll one-item arrays,
# so every list is wrapped in @() where it is assigned. The System.Array type
# data is removed so wrapped arrays serialise as arrays, not {value, Count}.

$ErrorActionPreference = 'SilentlyContinue'
$ProgressPreference = 'SilentlyContinue'
Remove-TypeData -TypeName System.Array -ErrorAction SilentlyContinue

$maxHotfixes = 200
$maxDrivers = 200
$maxStartup = 100
$maxServices = 200
$maxTasks = 200
$maxUsbDevices = 100

$windowsApplicationId = '55c92734-d682-4d71-983e-d6ec3f16059f'
$administratorsSid = 'S-1-5-32-544'

$chassisTypes = @{ 1 = 'Other'; 2 = 'Unknown'; 3 = 'Desktop'; 4 = 'Low Profile Desktop'; 5 = 'Pizza Box'; 6 = 'Mini Tower'; 7 = 'Tower'; 8 = 'Portable'; 9 = 'Laptop'; 10 = 'Notebook'; 11 = 'Hand Held'; 12 = 'Docking Station'; 13 = 'All in One'; 14 = 'Sub Notebook'; 15 = 'Space-saving'; 16 = 'Lunch Box'; 17 = 'Main System Chassis'; 23 = 'Rack Mount Chassis'; 24 = 'Sealed-case PC'; 30 = 'Tablet'; 31 = 'Convertible'; 32 = 'Detachable'; 33 = 'IoT Gateway'; 34 = 'Embedded PC'; 35 = 'Mini PC'; 36 = 'Stick PC' }
$mediaTypes = @{ 0 = 'Unspecified'; 3 = 'HDD'; 4 = 'SSD'; 5 = 'SCM' }
$busTypes = @{ 0 = 'Unknown'; 1 = 'SCSI'; 2 = 'ATAPI'; 3 = 'ATA'; 4 = '1394'; 5 = 'SSA'; 6 = 'Fibre Channel'; 7 = 'USB'; 8 = 'RAID'; 9 = 'iSCSI'; 10 = 'SAS'; 11 = 'SATA'; 12 = 'SD'; 13 = 'MMC'; 14 = 'Virtual'; 15 = 'File Backed Virtual'; 16 = 'Storage Spaces'; 17 = 'NVMe'; 18 = 'SCM'; 19 = 'UFS' }
$healthStatuses = @{ 0 = 'Healthy'; 1 = 'Warning'; 2 = 'Unhealthy'; 5 = 'Unknown' }
$operationalStatuses = @{ 0 = 'Unknown'; 1 = 'Other'; 2 = 'OK'; 3 = 'Degraded'; 4 = 'Stressed'; 5 = 'Predictive Failure'; 6 = 'Error'; 7 = 'Non-Recoverable Error'; 8 = 'Starting'; 9 = 'Stopping'; 10 = 'Stopped'; 11 = 'In Service'; 12 = 'No Contact'; 13 = 'Lost Communication' }
$licenseStatuses = @{ 0 = 'Unlicensed'; 1 = 'Licensed'; 2 = 'Out-of-box grace'; 3 = 'Out-of-tolerance grace'; 4 = 'Non-genuine grace'; 5 = 'Notification'; 6 = 'Extended grace' }
$protectionStatuses = @{ 0 = 'Off'; 1 = 'On'; 2 = 'Unknown' }
$conversionStatuses = @{ 0 = 'Fully decrypted'; 1 = 'Fully encrypted'; 2 = 'Encryption in progress'; 3 = 'Decryption in progress'; 4 = 'Encryption paused'; 5 = 'Decryption paused' }
$vbsStatuses = @{ 0 = 'Off'; 1 = 'Configured, not running'; 2 = 'Running' }

function Get-Text($value) {
    if ($null -eq $value) { return $null }
    $text = ([string]$value).Trim()
    if ($text -eq '') { return $null }
    return $text
}

function Get-Coalesced($first, $second) {
    if ($null -ne $first) { return $first }
    return $second
}

function Format-Date($value) {
    if ($value -is [DateTime]) { return $value.ToString('o') }
    return $null
}

function Get-Gigabytes($bytes) {
    if ($null -eq $bytes) { return $null }
    return [Math]::Round([double]$bytes / 1GB, 1)
}

function Get-Label($labels, $code) {
    if ($null -eq $code) { return $null }
    $key = [int]$code
    if ($labels.ContainsKey($key)) { return $labels[$key] }
    return "Code $key"
}

function Convert-WmiString($codes) {
    if ($null -eq $codes) { return $null }
    $characters = @($codes | Where-Object { $_ -ne 0 } | ForEach-Object { [char][int]$_ })
    return Get-Text (-join $characters)
}

function Get-RegistryValue($path, $name) {
    $item = Get-ItemProperty -Path $path -Name $name -ErrorAction SilentlyContinue
    if ($null -eq $item) { return $null }
    return $item.$name
}

function Get-AdministratorsViaAdsi {
    $sid = New-Object System.Security.Principal.SecurityIdentifier($administratorsSid)
    $groupName = $sid.Translate([System.Security.Principal.NTAccount]).Value.Split('\')[-1]
    $group = [ADSI]"WinNT://$env:COMPUTERNAME/$groupName,group"
    @($group.Invoke('Members')) | ForEach-Object {
        $path = [string]$_.GetType().InvokeMember('ADsPath', 'GetProperty', $null, $_, $null)
        $class = [string]$_.GetType().InvokeMember('Class', 'GetProperty', $null, $_, $null)
        $source = 'Domain or Entra ID'
        if ($path -like "WinNT://$env:COMPUTERNAME/*") { $source = 'Local' }
        [ordered]@{
            name = ($path -replace '^WinNT://', '') -replace '/', '\'
            object_class = $class
            principal_source = $source
        }
    }
}

$os = Get-CimInstance -ClassName Win32_OperatingSystem -ErrorAction SilentlyContinue | Select-Object -First 1
$computer = Get-CimInstance -ClassName Win32_ComputerSystem -ErrorAction SilentlyContinue | Select-Object -First 1

if ($null -eq $os -and $null -eq $computer) {
    Write-Output 'ATTENTION: could not read Win32_OperatingSystem or Win32_ComputerSystem, CIM looks broken on this PC'
    exit 1
}

# System
$bios = Get-CimInstance -ClassName Win32_BIOS -ErrorAction SilentlyContinue | Select-Object -First 1
$board = Get-CimInstance -ClassName Win32_BaseBoard -ErrorAction SilentlyContinue | Select-Object -First 1
$enclosure = Get-CimInstance -ClassName Win32_SystemEnclosure -ErrorAction SilentlyContinue | Select-Object -First 1

$chassisType = $null
if ($enclosure -and $enclosure.ChassisTypes) {
    $chassisType = Get-Label $chassisTypes (@($enclosure.ChassisTypes)[0])
}

$system = [ordered]@{
    manufacturer = Get-Text $computer.Manufacturer
    model = Get-Text $computer.Model
    serial_number = Get-Text $bios.SerialNumber
    sku = Get-Text $computer.SystemSKUNumber
    family = Get-Text $computer.SystemFamily
    chassis_type = $chassisType
    bios = [ordered]@{
        vendor = Get-Text $bios.Manufacturer
        version = Get-Text $bios.SMBIOSBIOSVersion
        release_date = Format-Date $bios.ReleaseDate
    }
    baseboard = [ordered]@{
        manufacturer = Get-Text $board.Manufacturer
        product = Get-Text $board.Product
        serial = Get-Text $board.SerialNumber
    }
}

# CPU
$processors = @(Get-CimInstance -ClassName Win32_Processor -ErrorAction SilentlyContinue)
$cpu = $null
if ($processors.Count -gt 0) {
    $cpu = [ordered]@{
        name = Get-Text $processors[0].Name
        sockets = $processors.Count
        cores = [int](($processors | Measure-Object -Property NumberOfCores -Sum).Sum)
        logical_processors = [int](($processors | Measure-Object -Property NumberOfLogicalProcessors -Sum).Sum)
        max_clock_mhz = $processors[0].MaxClockSpeed
    }
}

# Memory
$memoryModules = @(Get-CimInstance -ClassName Win32_PhysicalMemory -ErrorAction SilentlyContinue | ForEach-Object {
    [ordered]@{
        slot = Get-Text $_.DeviceLocator
        bank = Get-Text $_.BankLabel
        capacity_gb = Get-Gigabytes $_.Capacity
        speed_mhz = Get-Coalesced $_.ConfiguredClockSpeed $_.Speed
        manufacturer = Get-Text $_.Manufacturer
        part_number = Get-Text $_.PartNumber
        serial = Get-Text $_.SerialNumber
    }
})

$installedBytes = (Get-CimInstance -ClassName Win32_PhysicalMemory -ErrorAction SilentlyContinue | Measure-Object -Property Capacity -Sum).Sum
$totalMemoryGb = $null
if ($installedBytes -gt 0) {
    $totalMemoryGb = Get-Gigabytes $installedBytes
} elseif ($computer -and $computer.TotalPhysicalMemory) {
    $totalMemoryGb = Get-Gigabytes $computer.TotalPhysicalMemory
}

$memoryArrays = @(Get-CimInstance -ClassName Win32_PhysicalMemoryArray -ErrorAction SilentlyContinue)
$memorySlots = $null
if ($memoryArrays.Count -gt 0) {
    $memorySlots = [int](($memoryArrays | Measure-Object -Property MemoryDevices -Sum).Sum)
}

$memory = [ordered]@{
    total_gb = $totalMemoryGb
    slots_total = $memorySlots
    modules = $memoryModules
}

# Disks and volumes
$disks = @(Get-CimInstance -Namespace root/Microsoft/Windows/Storage -ClassName MSFT_PhysicalDisk -ErrorAction SilentlyContinue | ForEach-Object {
    [ordered]@{
        model = Get-Text $_.FriendlyName
        serial = Get-Text $_.SerialNumber
        size_gb = Get-Gigabytes $_.Size
        media_type = Get-Label $mediaTypes $_.MediaType
        bus_type = Get-Label $busTypes $_.BusType
        health = Get-Label $healthStatuses $_.HealthStatus
        operational_status = Get-Label $operationalStatuses (@($_.OperationalStatus)[0])
    }
})

if ($disks.Count -eq 0) {
    $disks = @(Get-CimInstance -ClassName Win32_DiskDrive -ErrorAction SilentlyContinue | ForEach-Object {
        [ordered]@{
            model = Get-Text $_.Model
            serial = Get-Text $_.SerialNumber
            size_gb = Get-Gigabytes $_.Size
            media_type = Get-Text $_.MediaType
            bus_type = Get-Text $_.InterfaceType
            health = Get-Text $_.Status
            operational_status = $null
        }
    })
}

$volumes = @(Get-CimInstance -ClassName Win32_LogicalDisk -Filter 'DriveType = 3' -ErrorAction SilentlyContinue | ForEach-Object {
    [ordered]@{
        drive = Get-Text $_.DeviceID
        label = Get-Text $_.VolumeName
        file_system = Get-Text $_.FileSystem
        size_gb = Get-Gigabytes $_.Size
        free_gb = Get-Gigabytes $_.FreeSpace
    }
})

# Graphics, monitors and printers
$gpus = @(Get-CimInstance -ClassName Win32_VideoController -ErrorAction SilentlyContinue | ForEach-Object {
    $resolution = $null
    if ($_.CurrentHorizontalResolution -and $_.CurrentVerticalResolution) {
        $resolution = "$($_.CurrentHorizontalResolution)x$($_.CurrentVerticalResolution)"
    }
    [ordered]@{
        name = Get-Text $_.Name
        driver_version = Get-Text $_.DriverVersion
        driver_date = Format-Date $_.DriverDate
        resolution = $resolution
    }
})

$monitors = @(Get-CimInstance -Namespace root/wmi -ClassName WmiMonitorID -ErrorAction SilentlyContinue | ForEach-Object {
    [ordered]@{
        manufacturer = Convert-WmiString $_.ManufacturerName
        name = Convert-WmiString $_.UserFriendlyName
        product_code = Convert-WmiString $_.ProductCodeID
        serial = Convert-WmiString $_.SerialNumberID
        year = $_.YearOfManufacture
        week = $_.WeekOfManufacture
    }
})

$printers = @(Get-CimInstance -ClassName Win32_Printer -ErrorAction SilentlyContinue | ForEach-Object {
    [ordered]@{
        name = Get-Text $_.Name
        driver = Get-Text $_.DriverName
        port = Get-Text $_.PortName
        is_default = [bool]$_.Default
        is_shared = [bool]$_.Shared
        is_network = [bool]$_.Network
    }
})

# USB and input devices
$usbDevices = @(Get-CimInstance -ClassName Win32_PnPEntity -ErrorAction SilentlyContinue |
    Where-Object { $_.PNPDeviceID -like 'USB\*' -and $_.Name } |
    Group-Object -Property Name |
    ForEach-Object { $_.Group[0] } |
    Select-Object -First $maxUsbDevices |
    ForEach-Object {
        [ordered]@{
            name = Get-Text $_.Name
            manufacturer = Get-Text $_.Manufacturer
            class = Get-Text $_.PNPClass
            status = Get-Text $_.Status
        }
    })

$keyboards = @(Get-CimInstance -ClassName Win32_Keyboard -ErrorAction SilentlyContinue |
    Where-Object { $_.Name } |
    Group-Object -Property Name |
    ForEach-Object { [ordered]@{ name = Get-Text $_.Group[0].Name; manufacturer = Get-Text $_.Group[0].Manufacturer } })

$pointingDevices = @(Get-CimInstance -ClassName Win32_PointingDevice -ErrorAction SilentlyContinue |
    Where-Object { $_.Name } |
    Group-Object -Property Name |
    ForEach-Object { [ordered]@{ name = Get-Text $_.Group[0].Name; manufacturer = Get-Text $_.Group[0].Manufacturer } })

$inputDevices = [ordered]@{
    keyboards = $keyboards
    pointing_devices = $pointingDevices
}

# Network adapters
$networkAdapters = @(Get-NetAdapter -Physical -ErrorAction SilentlyContinue | ForEach-Object {
    [ordered]@{
        name = Get-Text $_.Name
        description = Get-Text $_.InterfaceDescription
        mac_address = Get-Text $_.MacAddress
        link_speed = Get-Text $_.LinkSpeed
        status = Get-Text $_.Status
        driver_version = Get-Text $_.DriverVersionString
        driver_date = Get-Text $_.DriverDate
        driver_provider = Get-Text $_.DriverProvider
    }
})

if ($networkAdapters.Count -eq 0) {
    $networkAdapters = @(Get-CimInstance -ClassName Win32_NetworkAdapter -Filter 'PhysicalAdapter = TRUE' -ErrorAction SilentlyContinue | ForEach-Object {
        [ordered]@{
            name = Get-Text $_.NetConnectionID
            description = Get-Text $_.Name
            mac_address = Get-Text $_.MACAddress
            link_speed = $null
            status = $null
            driver_version = $null
            driver_date = $null
            driver_provider = Get-Text $_.Manufacturer
        }
    })
}

# Battery
$battery = $null
$batteryInfo = Get-CimInstance -ClassName Win32_Battery -ErrorAction SilentlyContinue | Select-Object -First 1
if ($batteryInfo) {
    $designCapacity = (Get-CimInstance -Namespace root/wmi -ClassName BatteryStaticData -ErrorAction SilentlyContinue | Select-Object -First 1).DesignedCapacity
    $fullChargeCapacity = (Get-CimInstance -Namespace root/wmi -ClassName BatteryFullChargedCapacity -ErrorAction SilentlyContinue | Select-Object -First 1).FullChargedCapacity
    $healthPercent = $null
    if ($designCapacity -gt 0 -and $fullChargeCapacity -gt 0) {
        $healthPercent = [Math]::Round(([double]$fullChargeCapacity / [double]$designCapacity) * 100)
    }
    $battery = [ordered]@{
        name = Get-Text $batteryInfo.Name
        charge_percent = $batteryInfo.EstimatedChargeRemaining
        design_capacity_mwh = $designCapacity
        full_charge_capacity_mwh = $fullChargeCapacity
        health_percent = $healthPercent
    }
}

# Windows
$currentVersionKey = 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'
$displayVersion = Get-Coalesced (Get-Text (Get-RegistryValue $currentVersionKey 'DisplayVersion')) (Get-Text (Get-RegistryValue $currentVersionKey 'ReleaseId'))

$timeSource = $null
$timeStatus = @(& w32tm.exe /query /status 2>$null)
$sourceLine = $timeStatus | Where-Object { $_ -match '^\s*Source:\s*(.+)$' } | Select-Object -First 1
if ($sourceLine -match '^\s*Source:\s*(.+)$') {
    $timeSource = $Matches[1].Trim()
}

$license = Get-CimInstance -ClassName SoftwareLicensingProduct -Filter "ApplicationID = '$windowsApplicationId' AND PartialProductKey IS NOT NULL" -ErrorAction SilentlyContinue | Select-Object -First 1
$activation = $null
if ($license) {
    $activation = [ordered]@{
        license_status = Get-Label $licenseStatuses $license.LicenseStatus
        channel = Get-Text $license.ProductKeyChannel
        description = Get-Text $license.Description
    }
}

$windows = [ordered]@{
    caption = Get-Text $os.Caption
    edition_id = Get-Text (Get-RegistryValue $currentVersionKey 'EditionID')
    display_version = $displayVersion
    build = Get-Coalesced (Get-Text (Get-RegistryValue $currentVersionKey 'CurrentBuild')) (Get-Text $os.BuildNumber)
    ubr = Get-RegistryValue $currentVersionKey 'UBR'
    architecture = Get-Text $os.OSArchitecture
    install_date = Format-Date $os.InstallDate
    last_boot = Format-Date $os.LastBootUpTime
    registered_owner = Get-Text $os.RegisteredUser
    registered_organization = Get-Text $os.Organization
    locale = Get-Text (Get-WinSystemLocale -ErrorAction SilentlyContinue).Name
    timezone = Get-Text ([System.TimeZoneInfo]::Local.Id)
    time_source = $timeSource
    domain = Get-Text $computer.Domain
    workgroup = Get-Text $computer.Workgroup
    is_domain_joined = [bool]$computer.PartOfDomain
    activation = $activation
}

# Security
$bitlocker = @(Get-CimInstance -Namespace root/cimv2/Security/MicrosoftVolumeEncryption -ClassName Win32_EncryptableVolume -ErrorAction SilentlyContinue | ForEach-Object {
    [ordered]@{
        drive = Get-Text $_.DriveLetter
        is_system_drive = ($_.DriveLetter -eq $env:SystemDrive)
        protection_status = Get-Label $protectionStatuses $_.ProtectionStatus
        conversion_status = Get-Label $conversionStatuses $_.ConversionStatus
    }
})

$secureBoot = $null
$secureBootResult = Confirm-SecureBootUEFI -ErrorAction SilentlyContinue
if ($secureBootResult -is [bool]) {
    $secureBoot = $secureBootResult
} else {
    $secureBootState = Get-RegistryValue 'HKLM:\SYSTEM\CurrentControlSet\Control\SecureBoot\State' 'UEFISecureBootEnabled'
    if ($null -ne $secureBootState) { $secureBoot = ([int]$secureBootState -eq 1) }
}

$tpmInfo = Get-CimInstance -Namespace root/cimv2/Security/MicrosoftTpm -ClassName Win32_Tpm -ErrorAction SilentlyContinue | Select-Object -First 1
$tpm = [ordered]@{
    is_present = ($null -ne $tpmInfo)
    is_enabled = $tpmInfo.IsEnabled_InitialValue
    is_activated = $tpmInfo.IsActivated_InitialValue
    spec_version = Get-Text (([string]$tpmInfo.SpecVersion).Split(',')[0])
}

$defenderStatus = Get-MpComputerStatus -ErrorAction SilentlyContinue
$defender = $null
if ($defenderStatus) {
    $defender = [ordered]@{
        is_service_enabled = [bool]$defenderStatus.AMServiceEnabled
        is_real_time_enabled = [bool]$defenderStatus.RealTimeProtectionEnabled
        signature_updated_at = Format-Date $defenderStatus.AntivirusSignatureLastUpdated
        signature_version = Get-Text $defenderStatus.AntivirusSignatureVersion
    }
}

$firewall = @(Get-NetFirewallProfile -ErrorAction SilentlyContinue | ForEach-Object {
    [ordered]@{
        name = Get-Text $_.Name
        is_enabled = ([string]$_.Enabled -eq 'True')
    }
})

$uacKey = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System'
$enableLua = Get-RegistryValue $uacKey 'EnableLUA'
$uac = [ordered]@{
    is_enabled = $(if ($null -eq $enableLua) { $null } else { [int]$enableLua -eq 1 })
    consent_prompt_admin = Get-RegistryValue $uacKey 'ConsentPromptBehaviorAdmin'
}

$deviceGuardInfo = Get-CimInstance -Namespace root/Microsoft/Windows/DeviceGuard -ClassName Win32_DeviceGuard -ErrorAction SilentlyContinue | Select-Object -First 1
$deviceGuard = $null
if ($deviceGuardInfo) {
    $deviceGuard = [ordered]@{
        vbs_status = Get-Label $vbsStatuses $deviceGuardInfo.VirtualizationBasedSecurityStatus
        is_credential_guard_running = (@($deviceGuardInfo.SecurityServicesRunning) -contains 1)
    }
}

$security = [ordered]@{
    bitlocker = $bitlocker
    secure_boot = $secureBoot
    tpm = $tpm
    defender = $defender
    firewall = $firewall
    uac = $uac
    device_guard = $deviceGuard
}

# Users
$localUsers = @(Get-LocalUser -ErrorAction SilentlyContinue | ForEach-Object {
    [ordered]@{
        name = Get-Text $_.Name
        is_enabled = [bool]$_.Enabled
        last_logon = Format-Date $_.LastLogon
        password_last_set = Format-Date $_.PasswordLastSet
        description = Get-Text $_.Description
    }
})

# Get-LocalGroupMember fails outright when the group holds an orphaned or
# Entra ID SID, so the group is read through ADSI when it returns nothing.
$localAdmins = @(Get-LocalGroupMember -SID $administratorsSid -ErrorAction SilentlyContinue | ForEach-Object {
    [ordered]@{
        name = Get-Text $_.Name
        object_class = Get-Text $_.ObjectClass
        principal_source = Get-Text $_.PrincipalSource
    }
})
if ($localAdmins.Count -eq 0) {
    $localAdmins = @(Get-AdministratorsViaAdsi)
}
if ($localAdmins.Count -eq 0) {
    $localAdmins = $null
}

# SYSTEM sees every session, so the owners of explorer.exe are the people
# signed in at the console or over RDP.
$loggedOn = @(Get-CimInstance -ClassName Win32_Process -Filter "Name = 'explorer.exe'" -ErrorAction SilentlyContinue | ForEach-Object {
    $owner = Invoke-CimMethod -InputObject $_ -MethodName GetOwner -ErrorAction SilentlyContinue
    if ($owner -and $owner.User) {
        [ordered]@{
            name = "$($owner.Domain)\$($owner.User)"
            session_id = $_.SessionId
            since = Format-Date $_.CreationDate
        }
    }
} | Group-Object -Property { $_.name } | ForEach-Object { $_.Group[0] })

$profiles = @(Get-CimInstance -ClassName Win32_UserProfile -Filter 'Special = FALSE' -ErrorAction SilentlyContinue |
    Sort-Object -Property LastUseTime -Descending |
    ForEach-Object {
        [ordered]@{
            path = Get-Text $_.LocalPath
            last_used = Format-Date $_.LastUseTime
            is_loaded = [bool]$_.Loaded
        }
    })

$users = [ordered]@{
    local_users = $localUsers
    local_admins = $localAdmins
    logged_on = $loggedOn
    profiles = $profiles
}

# Updates
$hotfixes = @(Get-HotFix -ErrorAction SilentlyContinue |
    Sort-Object -Property InstalledOn -Descending |
    Select-Object -First $maxHotfixes |
    ForEach-Object {
        [ordered]@{
            id = Get-Text $_.HotFixID
            installed_on = Format-Date $_.InstalledOn
            description = Get-Text $_.Description
        }
    })

$rebootReasons = @()
if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending') {
    $rebootReasons += 'Component servicing'
}
if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired') {
    $rebootReasons += 'Windows Update'
}
if (Get-RegistryValue 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager' 'PendingFileRenameOperations') {
    $rebootReasons += 'Pending file renames'
}

$updates = [ordered]@{
    hotfixes = $hotfixes
    pending_reboot = [ordered]@{
        is_pending = ($rebootReasons.Count -gt 0)
        reasons = $rebootReasons
    }
}

# Software environment
$dotnetKey = 'HKLM:\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full'
$powershellVersions = @()
$windowsPowerShellVersion = Get-Text (Get-RegistryValue 'HKLM:\SOFTWARE\Microsoft\PowerShell\3\PowerShellEngine' 'PowerShellVersion')
if ($windowsPowerShellVersion) {
    $powershellVersions += $windowsPowerShellVersion
}
$powershellVersions += @(Get-ChildItem -Path "$env:ProgramFiles\PowerShell\*\pwsh.exe" -ErrorAction SilentlyContinue |
    ForEach-Object { Get-Text $_.VersionInfo.ProductVersion } |
    Where-Object { $_ })

$optionalFeatures = @(Get-WindowsOptionalFeature -Online -ErrorAction SilentlyContinue |
    Where-Object { [string]$_.State -eq 'Enabled' } |
    ForEach-Object { [string]$_.FeatureName } |
    Sort-Object)

$softwareEnvironment = [ordered]@{
    dotnet_framework = [ordered]@{
        release = Get-RegistryValue $dotnetKey 'Release'
        version = Get-Text (Get-RegistryValue $dotnetKey 'Version')
    }
    powershell_versions = $powershellVersions
    optional_features = $optionalFeatures
}

# Third-party drivers, startup items, services and scheduled tasks
$drivers = @(Get-CimInstance -ClassName Win32_PnPSignedDriver -ErrorAction SilentlyContinue |
    Where-Object { $_.DeviceName -and $_.DriverProviderName -and $_.DriverProviderName -notlike 'Microsoft*' } |
    Sort-Object -Property DeviceName |
    Select-Object -First $maxDrivers |
    ForEach-Object {
        [ordered]@{
            device_name = Get-Text $_.DeviceName
            provider = Get-Text $_.DriverProviderName
            version = Get-Text $_.DriverVersion
            date = Format-Date $_.DriverDate
            is_signed = [bool]$_.IsSigned
        }
    })

$startup = @(Get-CimInstance -ClassName Win32_StartupCommand -ErrorAction SilentlyContinue |
    Select-Object -First $maxStartup |
    ForEach-Object {
        [ordered]@{
            name = Get-Text $_.Name
            command = Get-Text $_.Command
            location = Get-Text $_.Location
            user = Get-Text $_.User
        }
    })

$windowsPathPattern = '^("?)(' + [regex]::Escape($env:SystemRoot) + '\\|%SystemRoot%\\|\\SystemRoot\\)'
$services = @(Get-CimInstance -ClassName Win32_Service -ErrorAction SilentlyContinue |
    Where-Object { $_.PathName -and $_.PathName -notmatch $windowsPathPattern } |
    Sort-Object -Property Name |
    Select-Object -First $maxServices |
    ForEach-Object {
        [ordered]@{
            name = Get-Text $_.Name
            display_name = Get-Text $_.DisplayName
            state = Get-Text $_.State
            start_mode = Get-Text $_.StartMode
        }
    })

$scheduledTasks = @(Get-ScheduledTask -ErrorAction SilentlyContinue |
    Where-Object { $_.TaskPath -notlike '\Microsoft\*' } |
    Sort-Object -Property TaskPath, TaskName |
    Select-Object -First $maxTasks |
    ForEach-Object {
        [ordered]@{
            path = Get-Text $_.TaskPath
            name = Get-Text $_.TaskName
            state = Get-Text ([string]$_.State)
        }
    })

$inventory = [ordered]@{
    system = $system
    cpu = $cpu
    memory = $memory
    disks = $disks
    volumes = $volumes
    gpus = $gpus
    monitors = $monitors
    printers = $printers
    usb_devices = $usbDevices
    input_devices = $inputDevices
    network_adapters = $networkAdapters
    battery = $battery
    windows = $windows
    security = $security
    users = $users
    updates = $updates
    software_environment = $softwareEnvironment
    drivers = $drivers
    startup = $startup
    services_non_microsoft = $services
    scheduled_tasks_non_microsoft = $scheduledTasks
    collected_at = (Get-Date).ToString('o')
}

$json = ConvertTo-Json -InputObject $inventory -Depth 6 -Compress

if (-not $json) {
    Write-Output 'ATTENTION: the inventory was collected but could not be written as JSON'
    exit 1
}

Write-Output $json
exit 0
