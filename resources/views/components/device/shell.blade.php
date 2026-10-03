@props(['device', 'current'])

<div {{ $attributes->class('space-y-6') }}>
    <div class="space-y-4">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('devices.index')" wire:navigate>Devices</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $device->hostname }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <livewire:devices.header :device="$device" :key="'device-header-'.$device->id" />
    </div>

    <div class="border-b border-zinc-200 dark:border-zinc-700">
        <flux:navbar scrollable class="-mb-px">
            @foreach(App\Enums\DeviceTab::cases() as $tab)
                <flux:navbar.item :href="route($tab->routeName(), $device)" :icon="$tab->icon()" :current="$tab === $current" wire:navigate wire:key="device-tab-{{ $tab->value }}">
                    {{ $tab->label() }}
                </flux:navbar.item>
            @endforeach
        </flux:navbar>
    </div>

    {{ $slot }}
</div>
