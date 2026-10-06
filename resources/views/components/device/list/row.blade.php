@props(['device', 'latestVersion' => null])

<flux:table.row {{ $attributes }}>
    <flux:table.cell>
        <flux:checkbox wire:model.live="selectedDevices" value="{{ $device->id }}" />
    </flux:table.cell>
    <flux:table.cell>
        <x-device.list.identity :device="$device" :show-group="false" />
    </flux:table.cell>
    <flux:table.cell>
        <div class="flex flex-col items-start gap-1">
            <div class="flex flex-wrap items-center gap-1">
                <x-device.status-badge :label="$device->statusLabel()" :color="$device->statusColor()" size="sm" />
                <x-device.monitor-only-badge :device="$device" />
            </div>
            <x-device.list.activity :in-flight="$device->inFlight()" />
        </div>
    </flux:table.cell>
    <flux:table.cell class="hidden xl:table-cell">
        @if($device->group)
            <flux:badge size="sm" :color="$device->group->color ?? 'zinc'">{{ $device->group->name }}</flux:badge>
        @else
            <span class="text-sm text-zinc-400 dark:text-zinc-500">—</span>
        @endif
    </flux:table.cell>
    <flux:table.cell class="hidden lg:table-cell" align="end">
        <x-device.list.metric-value :value="$device->latestMetric?->cpuRoundedForHumans()" :color="$device->latestMetric?->cpuTextColor() ?? 'text-zinc-600 dark:text-zinc-300'" />
    </flux:table.cell>
    <flux:table.cell class="hidden lg:table-cell" align="end">
        <x-device.list.metric-value :value="$device->latestMetric?->ramRoundedForHumans()" :color="$device->latestMetric?->ramTextColor() ?? 'text-zinc-600 dark:text-zinc-300'" />
    </flux:table.cell>
    <flux:table.cell class="hidden lg:table-cell" align="end">
        <x-device.list.disk-value :disk="$device->fullestDisk()" />
    </flux:table.cell>
    <flux:table.cell class="hidden 2xl:table-cell">
        <div class="flex flex-col items-start gap-1">
            <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-300">{{ $device->agent_version ?? '—' }}</span>
            <x-device.agent-update-badge :device="$device" :latest-version="$latestVersion" />
        </div>
    </flux:table.cell>
    <flux:table.cell class="hidden sm:table-cell">
        <span class="text-sm text-zinc-600 dark:text-zinc-300" title="{{ $device->lastSeenAt() }}">{{ $device->lastSeenCoarse() }}</span>
    </flux:table.cell>
    <flux:table.cell>
        <x-device.list.actions :device="$device" />
    </flux:table.cell>
</flux:table.row>
