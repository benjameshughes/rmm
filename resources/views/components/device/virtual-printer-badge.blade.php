@props(['device'])

@if($device->isVirtualPrinterDown)
    <flux:badge size="sm" color="red" icon="printer" data-virtual-printer-down {{ $attributes }}>
        {{ config('devices.watched_apps.virtual_printer.label') }} &middot; {{ $device->virtualPrinterLabel() }}
    </flux:badge>
@endif
