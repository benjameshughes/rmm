@props(['hardware'])

<x-dashboard.section title="Hardware" :description="$hardware->memorySummary()" data-hardware>
    <flux:accordion transition>
        <x-inventory.collapsible :table="$hardware->memoryModules()" heading="Memory" name="memory" />
        <x-inventory.collapsible :table="$hardware->disks()" heading="Disks" name="disks" />
        <x-inventory.collapsible :table="$hardware->volumes()" heading="Volumes" name="volumes" />
        <x-inventory.collapsible :table="$hardware->gpus()" heading="Graphics" name="gpus" />
        <x-inventory.collapsible :table="$hardware->monitors()" heading="Monitors" name="monitors" />
        <x-inventory.collapsible :table="$hardware->printers()" heading="Printers" name="printers" />
        <x-inventory.collapsible :table="$hardware->networkAdapters()" heading="Network adapters" name="network-adapters" />
        <x-inventory.collapsible :table="$hardware->usbDevices()" heading="USB devices" name="usb-devices" />
        <x-inventory.collapsible :table="$hardware->inputDevices()" heading="Keyboards and mice" name="input-devices" />
    </flux:accordion>

    <div class="space-y-2">
        <flux:heading size="sm">Battery</flux:heading>
        <x-inventory.facts :facts="$hardware->battery()" empty="No battery." />
    </div>
</x-dashboard.section>
