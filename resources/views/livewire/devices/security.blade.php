<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Security">
    @island(name: 'security', always: true)
        @if($this->hasLinuxSecurityReport)
            @if($this->linuxSecurity)
                <x-inventory.linux-security :security="$this->linuxSecurity" :collected="$device->systemInventoryCollectedForHumans()" />
            @else
                <x-inventory.linux-security-empty />
            @endif
        @elseif(! $this->hasSystemInventory)
            <x-inventory.unsupported />
        @else
            <livewire:devices.system-inventory :device="$device" :key="'system-inventory-'.$device->id" />

            @if($this->security)
                <x-inventory.security :security="$this->security" />
            @else
                <x-inventory.empty />
            @endif
        @endif
    @endisland
</x-device.shell>
