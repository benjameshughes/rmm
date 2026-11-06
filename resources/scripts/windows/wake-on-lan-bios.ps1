# Sets the BIOS half of Wake-on-LAN from Windows on Dell, HP and Lenovo
# business PCs, through the BIOS WMI interface each vendor builds in, so
# nothing needs installing: wake on LAN on, the deep power saving that cuts
# the network card's power when shut down off, and power restored after a
# cut. Dell from 2018, HP from roughly the G1/G2 era, Lenovo ThinkCentre and
# ThinkPad.
#
# Vendors and models name these settings differently, so each one is a list
# of known names and values. The first name the BIOS has is used and the
# value is checked against what it offers before setting it; anything that
# matches nothing is reported with what the BIOS does offer, never guessed.
# Changes apply at the next restart. A BIOS admin password blocks this.

$wantedByVendor = @{
    Dell = @(
        @{ Label = 'Wake on LAN'; Names = @('WakeOnLan'); Values = @('LanOnly') }
        @{ Label = 'Deep sleep'; Names = @('DeepSleepCtrl'); Values = @('Disabled') }
        @{ Label = 'Power loss'; Names = @('AcPwrRcvry'); Values = @('Last') }
    )
    HP = @(
        @{ Label = 'Wake on LAN'; Names = @('S5 Wake on LAN', 'S4/S5 Wake On LAN', 'Wake On LAN'); Values = @('Enable') }
        @{ Label = 'Deep sleep'; Names = @('S5 Maximum Power Savings', 'S4/S5 Max Power Savings'); Values = @('Disable') }
        @{ Label = 'Power loss'; Names = @('After Power Loss', 'Power State After Power Loss'); Values = @('Previous State', 'Power On') }
    )
    Lenovo = @(
        @{ Label = 'Wake on LAN'; Names = @('Wake on LAN', 'WakeOnLAN'); Values = @('Automatic', 'ACOnly', 'Primary', 'Enabled', 'Enable') }
        @{ Label = 'Deep sleep'; Names = @('ErP LPS', 'ErP LPS Mode', 'Enhanced Power Saving Mode', 'ErP'); Values = @('Disabled', 'Disable') }
        @{ Label = 'Power loss'; Names = @('After Power Loss', 'AfterPowerLoss'); Values = @('Last State', 'LastState', 'Power On') }
    )
}

function Get-BiosSettings([string] $vendor) {
    switch ($vendor) {
        'Dell' {
            Get-CimInstance -Namespace 'root\dcim\sysman\biosattributes' -ClassName EnumerationAttribute -ErrorAction SilentlyContinue |
                ForEach-Object { [pscustomobject]@{ Name = $_.AttributeName; Current = "$($_.CurrentValue)"; Offers = @($_.PossibleValue) } }
        }
        'HP' {
            Get-CimInstance -Namespace 'root\HP\InstrumentedBIOS' -ClassName HP_BIOSEnumeration -ErrorAction SilentlyContinue |
                ForEach-Object { [pscustomobject]@{ Name = $_.Name; Current = "$($_.CurrentValue)"; Offers = @($_.PossibleValues) } }
        }
        'Lenovo' {
            $selections = Get-CimInstance -Namespace 'root\wmi' -ClassName Lenovo_GetBiosSelections -ErrorAction SilentlyContinue | Select-Object -First 1

            Get-CimInstance -Namespace 'root\wmi' -ClassName Lenovo_BiosSetting -ErrorAction SilentlyContinue |
                Where-Object { $_.CurrentSetting } |
                ForEach-Object {
                    $name, $current = ($_.CurrentSetting -split ';')[0] -split ',', 2
                    $offers = if ($selections) { @("$((Invoke-CimMethod -InputObject $selections -MethodName GetBiosSelections -Arguments @{ Item = $name }).Selections)" -split ',') } else { @() }
                    [pscustomobject]@{ Name = $name; Current = $current; Offers = $offers }
                }
        }
    }
}

function Set-BiosSetting([string] $vendor, [string] $name, [string] $value) {
    switch ($vendor) {
        'Dell' {
            $interface = Get-CimInstance -Namespace 'root\dcim\sysman\biosattributes' -ClassName BIOSAttributeInterface
            $result = Invoke-CimMethod -InputObject $interface -MethodName SetAttribute -Arguments @{
                SecType = [uint32]0; SecHndCount = [uint32]0; SecHandle = [byte[]]@(); AttributeName = $name; AttributeValue = $value
            }
            return $result.Status -eq 0
        }
        'HP' {
            $interface = Get-CimInstance -Namespace 'root\HP\InstrumentedBIOS' -ClassName HP_BIOSSettingInterface
            $result = Invoke-CimMethod -InputObject $interface -MethodName SetBIOSSetting -Arguments @{ Name = $name; Value = $value; Password = '<utf-16/>' }
            return $result.Return -eq 0
        }
        'Lenovo' {
            $setter = Get-CimInstance -Namespace 'root\wmi' -ClassName Lenovo_SetBiosSetting
            $result = Invoke-CimMethod -InputObject $setter -MethodName SetBiosSetting -Arguments @{ parameter = "$name,$value" }
            return $result.return -eq 'Success'
        }
    }
}

function Test-BiosPassword([string] $vendor) {
    switch ($vendor) {
        'Dell' {
            [bool](Get-CimInstance -Namespace 'root\dcim\sysman\wmisecurity' -ClassName PasswordObject -ErrorAction SilentlyContinue |
                Where-Object { $_.NameId -eq 'Admin' -and $_.IsPasswordSet -eq 1 })
        }
        'HP' {
            [bool](Get-CimInstance -Namespace 'root\HP\InstrumentedBIOS' -ClassName HP_BIOSPassword -ErrorAction SilentlyContinue |
                Where-Object { $_.Name -eq 'Setup Password' -and $_.IsSet -eq 1 })
        }
        'Lenovo' {
            $state = (Get-CimInstance -Namespace 'root\wmi' -ClassName Lenovo_BiosPasswordSettings -ErrorAction SilentlyContinue).PasswordState
            [bool]($state -band 2)
        }
    }
}

$manufacturer = "$((Get-CimInstance -ClassName Win32_ComputerSystem).Manufacturer)"
$vendor = switch -Regex ($manufacturer) {
    'Dell' { 'Dell' }
    'HP|Hewlett' { 'HP' }
    'Lenovo' { 'Lenovo' }
}

if (-not $vendor) {
    Stop-Run "this PC is made by $manufacturer, which this script does not know. Set Wake-on-LAN in its BIOS by hand"
}

$settings = @(Get-BiosSettings $vendor)

if ($settings.Count -eq 0) {
    Stop-Run "this $vendor has no BIOS WMI interface (too old, or an old BIOS). Update the BIOS or set it by hand"
}

if (Test-BiosPassword $vendor) {
    Stop-Run "a BIOS admin password is set on this $vendor, so Windows cannot change BIOS settings"
}

$problems = @()
$changed = @()

foreach ($wanted in $wantedByVendor[$vendor]) {
    $setting = $settings | Where-Object { $wanted.Names -contains $_.Name } | Select-Object -First 1

    if (-not $setting) {
        $problems += "$($wanted.Label): none of $($wanted.Names -join ', ') on this model"
        continue
    }

    if ($wanted.Values -contains $setting.Current) {
        Write-Output "$($setting.Name) already $($setting.Current)"
        continue
    }

    $value = if ($setting.Offers.Count -gt 0) { $wanted.Values | Where-Object { $setting.Offers -contains $_ } | Select-Object -First 1 } else { $wanted.Values[0] }

    if (-not $value) {
        $problems += "$($setting.Name) offers $($setting.Offers -join ', '), none of $($wanted.Values -join ', ')"
        continue
    }

    if (-not (Set-BiosSetting $vendor $setting.Name $value)) {
        $problems += "$($setting.Name) could not be set to $value"
        continue
    }

    Write-Output "$($setting.Name) $($setting.Current) -> $value"
    $changed += $setting.Name
}

if ($vendor -eq 'Lenovo' -and $changed.Count -gt 0) {
    $saver = Get-CimInstance -Namespace 'root\wmi' -ClassName Lenovo_SaveBiosSettings
    $saved = (Invoke-CimMethod -InputObject $saver -MethodName SaveBiosSettings -Arguments @{ parameter = '' }).return

    if ($saved -ne 'Success') {
        $problems += "Lenovo did not save the changes ($saved)"
    }
}

if ($problems.Count -gt 0) {
    Write-Output "ATTENTION: $vendor $($problems -join '; ')"
    exit 1
}

$verdict = if ($changed.Count -gt 0) { "OK: $vendor changed $($changed -join ', '). They apply at the next restart" } else { "OK: the $vendor BIOS was already set for Wake-on-LAN" }
Write-Output $verdict
exit 0
