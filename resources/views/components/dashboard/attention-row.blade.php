@props(['attention', 'latestVersion' => null])

@php($device = $attention->device)

<div {{ $attributes->class('flex flex-col gap-3 py-3 sm:flex-row sm:items-start') }}>
    <div class="flex min-w-0 items-start gap-3 sm:w-64 sm:shrink-0">
        <flux:icon :name="$attention->severity()->icon()" variant="mini" class="mt-0.5 shrink-0 {{ $attention->severity()->iconColor() }}" data-attention-severity="{{ $attention->severity()->value }}" />
        <div class="min-w-0 space-y-1">
            <a href="{{ route('devices.show', $device) }}" wire:navigate class="block truncate font-semibold text-zinc-800 hover:underline dark:text-white">{{ $device->hostname }}</a>
            <div class="flex flex-wrap items-center gap-1">
                <x-device.status-badge :label="$device->statusLabel()" :color="$device->statusColor()" size="sm" />
                <x-device.monitor-only-badge :device="$device" />
            </div>
        </div>
    </div>

    <div class="min-w-0 flex-1 space-y-2">
        @foreach($attention->disks as $disk)
            <div class="grid grid-cols-[4rem_1fr_3.5rem] items-center gap-3" wire:key="attention-{{ $device->id }}-disk-{{ $disk['name'] }}">
                <flux:text size="sm" class="truncate font-mono">{{ $disk['name'] }}</flux:text>
                <x-device.usage-bar :percent="$disk['usedPercent']" :color="$disk['barColor']" />
                <flux:text size="sm" class="text-right font-medium tabular-nums text-zinc-800 dark:text-white">{{ $disk['usedForHumans'] }}</flux:text>
            </div>
        @endforeach

        @foreach($attention->inodeDisks as $disk)
            <div class="flex items-center gap-3" wire:key="attention-{{ $device->id }}-inodes-{{ $disk['name'] }}" data-attention-inodes>
                <flux:text size="sm" class="w-16 truncate font-mono">{{ $disk['name'] }}</flux:text>
                <flux:text size="sm" class="font-medium {{ $disk['inodeColor'] }}">{{ $disk['inodeForHumans'] }}</flux:text>
            </div>
        @endforeach

        @foreach($attention->failedUnits as $unit)
            <div class="flex items-center gap-2" wire:key="attention-{{ $device->id }}-unit-{{ $unit }}">
                <flux:icon name="x-circle" variant="micro" class="text-red-500" />
                <flux:text size="sm" class="font-mono text-red-600 dark:text-red-400">{{ $unit }}</flux:text>
                <flux:text size="sm">failed</flux:text>
            </div>
        @endforeach

        @foreach($attention->alerts as $alert)
            <div class="flex flex-wrap items-center gap-2" wire:key="attention-{{ $device->id }}-alert-{{ $alert->id }}">
                <flux:badge size="sm" :color="$alert->severity->color()">{{ $alert->severity->label() }}</flux:badge>
                <flux:text size="sm" class="text-zinc-800 dark:text-white">{{ $alert->conditionLabel() }}</flux:text>
                <flux:text size="sm" class="font-mono">{{ $alert->valueLabel() }}</flux:text>
                <flux:text size="sm">since {{ $alert->triggered_at->diffForHumans() }}</flux:text>
            </div>
        @endforeach

        @if($attention->isOfflineUnexpectedly)
            <flux:text size="sm" class="flex items-center gap-2">
                <flux:icon name="signal-slash" variant="micro" class="text-red-500" />
                Went quiet without announcing a shutdown &middot; {{ $device->lastSeenForHumans() }}
            </flux:text>
        @endif

        @if($attention->isRebootRequired)
            <flux:badge size="sm" color="amber" icon="arrow-path">Reboot required</flux:badge>
        @endif

        @if($attention->isAgentBehind)
            <x-device.agent-update-badge :device="$device" :latest-version="$latestVersion" />
        @endif
    </div>
</div>
