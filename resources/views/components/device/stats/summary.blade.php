@props(['metric' => null])

<div {{ $attributes->class('grid gap-4 sm:grid-cols-2 lg:grid-cols-4') }}>
    <flux:card>
        <x-device.stats.stat label="CPU Usage" :value="$metric?->cpuForHumans()" />
    </flux:card>

    <flux:card>
        <x-device.stats.stat label="RAM Usage" :value="$metric?->ramForHumans()" :detail="$metric?->memoryUsageForHumans()" />
    </flux:card>

    <flux:card>
        @if($metric && ! $metric->hasLoadAverage)
            <x-device.stats.stat label="CPU Queue" :value="$metric->cpuQueueForHumans()" detail="threads waiting for a CPU" />
        @else
            <x-device.stats.stat label="Load Average" :value="$metric?->loadForHumans()" :detail="$metric?->loadTrendForHumans()" />
        @endif
    </flux:card>

    <flux:card>
        <x-device.stats.stat label="Uptime" :value="$metric?->uptimeForHumans()" />
    </flux:card>
</div>
