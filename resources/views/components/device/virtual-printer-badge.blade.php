@props(['device', 'size' => 'sm'])

@php($state = $device->virtualPrinterState())

@if($state !== \App\Enums\VirtualPrinterState::Unwatched)
    <flux:badge :size="$size" :color="$state->color()" :icon="$state->icon()" data-virtual-printer="{{ $state->value }}" {{ $attributes }}>
        {{ config('devices.watched_apps.virtual_printer.label') }} &middot; {{ $device->virtualPrinterLabel() }}
    </flux:badge>
@endif
