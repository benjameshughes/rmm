# Wake-on-LAN needs three things Windows can set and one it cannot: the BIOS
# setting. Every wired adapter is armed for magic packets, allowed to wake the
# machine, and Fast Startup is switched off so a shut-down PC still listens.
# Driver and feature updates quietly undo these, so this runs on a schedule.
#
# "Allow this device to wake the computer" is set through WMI
# (MSPower_DeviceWakeEnable): powercfg /deviceenablewake refuses as SYSTEM
# with "You do not have permission". Modern Standby PCs have no such switch
# for the NIC at all (no WMI entry, nothing wake-programmable): the platform
# keeps it listening, so only the magic packet setting counts there.

$adapters = @(Get-NetAdapter -Physical | Where-Object { $_.MediaType -eq '802.3' })

if ($adapters.Count -eq 0) {
    Write-Output 'ATTENTION: no wired adapters found. Wake-on-LAN does not work over Wi-Fi.'
    exit 1
}

$wakeSettings = @(Get-CimInstance -Namespace root/wmi -ClassName MSPower_DeviceWakeEnable -ErrorAction SilentlyContinue)

$results = $adapters | ForEach-Object {
    $name = $_.Name
    $pnpId = $_.PnPDeviceID

    Set-NetAdapterPowerManagement -Name $name -WakeOnMagicPacket Enabled -ErrorAction SilentlyContinue

    Get-NetAdapterAdvancedProperty -Name $name -ErrorAction SilentlyContinue |
        Where-Object { $_.DisplayName -match 'Magic Packet' } |
        ForEach-Object { Set-NetAdapterAdvancedProperty -Name $name -DisplayName $_.DisplayName -DisplayValue 'Enabled' -ErrorAction SilentlyContinue }

    $wake = @($wakeSettings | Where-Object { $_.InstanceName.StartsWith($pnpId, [StringComparison]::OrdinalIgnoreCase) })
    $wake | ForEach-Object { Set-CimInstance -InputObject $_ -Property @{ Enable = $true } -ErrorAction SilentlyContinue }

    $magicPacket = "$((Get-NetAdapterPowerManagement -Name $name -ErrorAction SilentlyContinue).WakeOnMagicPacket)"
    $wakeArmed = 'n/a'
    if ($wake.Count -gt 0) {
        $wakeArmed = "$(@(Get-CimInstance -Namespace root/wmi -ClassName MSPower_DeviceWakeEnable -ErrorAction SilentlyContinue |
            Where-Object { $_.InstanceName.StartsWith($pnpId, [StringComparison]::OrdinalIgnoreCase) -and $_.Enable }).Count -gt 0)"
    }

    [pscustomobject]@{
        Name = $name
        Mac = $_.MacAddress
        MagicPacket = $magicPacket
        WakeArmed = $wakeArmed
        IsReady = ($magicPacket -eq 'Enabled') -and ($wakeArmed -ne 'False')
    }
}

Set-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Power' -Name HiberbootEnabled -Value 0
$fastStartupOff = (Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Power' -Name HiberbootEnabled).HiberbootEnabled -eq 0

$notReady = @($results | Where-Object { -not $_.IsReady })

if ($notReady.Count -gt 0 -or -not $fastStartupOff) {
    $problems = @($notReady | ForEach-Object { "$($_.Name) not armed (magic packet $($_.MagicPacket), wake allowed $($_.WakeArmed))" })
    if (-not $fastStartupOff) {
        $problems += 'Fast Startup still on'
    }
    Write-Output "ATTENTION: $($problems -join '; ')"
} else {
    Write-Output "OK: $($results.Count) wired adapter(s) armed for Wake-on-LAN, Fast Startup off"
}

Write-Output ''
$results | Format-Table Name, Mac, MagicPacket, WakeArmed -AutoSize | Out-String -Width 200 | Write-Output

Write-Output 'Devices allowed to wake this PC:'
powercfg /devicequery wake_armed

Write-Output ''
Write-Output 'Sleep states supported:'
powercfg /a

Write-Output ''
Write-Output 'Still needed by hand: enable Wake-on-LAN (or "Power on by PCI-E") in the BIOS.'

if ($notReady.Count -gt 0 -or -not $fastStartupOff) {
    exit 1
}
