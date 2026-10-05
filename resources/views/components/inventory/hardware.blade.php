@props(['hardware'])

<x-dashboard.section title="Hardware" :description="$hardware->memorySummary()">
    <div class="space-y-6">
        <div class="space-y-2">
            <flux:heading size="sm">Memory</flux:heading>
            <x-inventory.table :table="$hardware->memoryModules()" name="memory" />
        </div>
        <div class="space-y-2">
            <flux:heading size="sm">Disks</flux:heading>
            <x-inventory.table :table="$hardware->disks()" name="disks" />
        </div>
        <div class="space-y-2">
            <flux:heading size="sm">Volumes</flux:heading>
            <x-inventory.table :table="$hardware->volumes()" name="volumes" />
        </div>
        <div class="space-y-2">
            <flux:heading size="sm">Graphics</flux:heading>
            <x-inventory.table :table="$hardware->gpus()" name="gpus" />
        </div>
        <div class="space-y-2">
            <flux:heading size="sm">Monitors</flux:heading>
            <x-inventory.table :table="$hardware->monitors()" name="monitors" />
        </div>
        <div class="space-y-2">
            <flux:heading size="sm">Printers</flux:heading>
            <x-inventory.table :table="$hardware->printers()" name="printers" />
        </div>
        <div class="space-y-2">
            <flux:heading size="sm">Network adapters</flux:heading>
            <x-inventory.table :table="$hardware->networkAdapters()" name="network-adapters" />
        </div>
        <div class="space-y-2">
            <flux:heading size="sm">USB devices</flux:heading>
            <x-inventory.table :table="$hardware->usbDevices()" name="usb-devices" />
        </div>
        <div class="space-y-2">
            <flux:heading size="sm">Keyboards and mice</flux:heading>
            <x-inventory.table :table="$hardware->inputDevices()" name="input-devices" />
        </div>
        <div class="space-y-2">
            <flux:heading size="sm">Battery</flux:heading>
            <x-inventory.facts :facts="$hardware->battery()" empty="No battery." />
        </div>
    </div>
</x-dashboard.section>
