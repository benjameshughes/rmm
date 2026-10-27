<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Security">
    @if($hasLinuxSecurityReport)
        @if($linuxSecurity)
            <x-inventory.linux-security :security="$linuxSecurity" :collected="$device->systemInventoryCollectedForHumans()" />
        @else
            <x-inventory.linux-security-empty />
        @endif
    @elseif(! $hasSystemInventory)
        <x-inventory.unsupported />
    @else
        <livewire:devices.system-inventory :device="$device" :key="'system-inventory-'.$device->id" />

        @if($security)
            <x-inventory.security :security="$security" />
        @else
            <x-inventory.empty />
        @endif
    @endif
</x-device.shell>
