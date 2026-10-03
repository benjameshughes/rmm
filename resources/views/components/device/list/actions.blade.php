@props(['device'])

<div {{ $attributes->class('flex items-center justify-end gap-1') }}>
    @if($device->isWakeable)
        <flux:tooltip content="Wake {{ $device->hostname }}">
            <flux:button size="sm" variant="ghost" icon="sun" square wire:click="wake({{ $device->id }})" aria-label="Wake {{ $device->hostname }}" />
        </flux:tooltip>
    @endif

    <flux:dropdown position="bottom" align="end">
        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" square aria-label="Actions for {{ $device->hostname }}" />

        <flux:menu>
            @foreach(App\Enums\DeviceTab::cases() as $tab)
                <flux:menu.item :icon="$tab->icon()" :href="route($tab->routeName(), $device)" wire:navigate wire:key="device-{{ $device->id }}-tab-{{ $tab->value }}">{{ $tab->label() }}</flux:menu.item>
            @endforeach

            <flux:menu.separator />

            <flux:menu.item icon="arrow-path" wire:click="restart({{ $device->id }})" wire:confirm="Are you sure you want to restart {{ $device->hostname }}?">Restart</flux:menu.item>
            <flux:menu.item icon="power" variant="danger" wire:click="powerOff({{ $device->id }})" wire:confirm="Are you sure you want to power off {{ $device->hostname }}?">Power Off</flux:menu.item>
            <flux:menu.item icon="arrow-down-tray" wire:click="checkForUpdates({{ $device->id }})">Check for Updates</flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>
