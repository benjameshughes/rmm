# Wake-on-LAN needs three things Windows can set and one it cannot: the BIOS
# setting. Every wired adapter is armed for magic packets, allowed to wake the
# machine, and Fast Startup is switched off so a shut-down PC still listens.

$adapters = Get-NetAdapter -Physical | Where-Object { $_.MediaType -eq '802.3' }

if (-not $adapters) {
    Write-Output 'No wired adapters found. Wake-on-LAN does not work over Wi-Fi.'
    exit 1
}

$adapters | ForEach-Object {
    $name = $_.Name

    Set-NetAdapterPowerManagement -Name $name -WakeOnMagicPacket Enabled -ErrorAction SilentlyContinue

    Get-NetAdapterAdvancedProperty -Name $name |
        Where-Object { $_.DisplayName -match 'Magic Packet' } |
        ForEach-Object { Set-NetAdapterAdvancedProperty -Name $name -DisplayName $_.DisplayName -DisplayValue 'Enabled' -ErrorAction SilentlyContinue }

    powercfg /deviceenablewake $_.InterfaceDescription | Out-Null

    $power = Get-NetAdapterPowerManagement -Name $name
    Write-Output "$name ($($_.MacAddress)): wake on magic packet $($power.WakeOnMagicPacket)"
}

Set-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Power' -Name HiberbootEnabled -Value 0
Write-Output 'Fast Startup disabled.'

Write-Output ''
Write-Output 'Devices allowed to wake this PC:'
powercfg /devicequery wake_armed

Write-Output ''
Write-Output 'Sleep states supported:'
powercfg /a

Write-Output ''
Write-Output 'Still needed by hand: enable Wake-on-LAN (or "Power on by PCI-E") in the BIOS.'
