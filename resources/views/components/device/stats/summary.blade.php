@props(['metric' => null, 'disk' => null])

<div {{ $attributes->class('grid gap-4 sm:grid-cols-2 lg:grid-cols-4') }}>
    <flux:card>
        <x-device.stats.stat label="CPU Usage" :value="$metric?->cpuForHumans()" :detail="$metric?->processorLoadForHumans()" />
    </flux:card>

    <flux:card>
        <x-device.stats.stat label="RAM Usage" :value="$metric?->ramForHumans()" :detail="$metric?->memoryUsageForHumans()" />
    </flux:card>

    <flux:card class="space-y-2">
        @if($disk && $disk['usedPercent'] !== null)
            <x-device.stats.stat :label="'Disk '.$disk['name']" :value="$disk['usedForHumans']" :detail="$disk['freeForHumans']" />
            <x-device.usage-bar :percent="$disk['usedPercent']" :color="$disk['barColor']" />
        @else
            <x-device.stats.stat label="Disk" />
        @endif
    </flux:card>

    <flux:card>
        <x-device.stats.stat label="Uptime" :value="$metric?->uptimeForHumans()" />
    </flux:card>
</div>
