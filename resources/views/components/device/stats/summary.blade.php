@props(['metric' => null, 'disk' => null, 'swapLabel' => 'Page File', 'missingSwap' => '—'])

<div {{ $attributes->class('grid grid-cols-2 gap-4 xl:grid-cols-5') }}>
    <x-device.stats.usage-card label="CPU Usage" :value="$metric?->cpuForHumans()" :detail="$metric?->processorLoadForHumans()" :percent="$metric?->cpu" :color="$metric?->cpuBarColor()" />

    <x-device.stats.usage-card label="RAM Usage" :value="$metric?->ramForHumans()" :detail="$metric?->memoryUsageForHumans()" :percent="$metric?->ram" :color="$metric?->ramBarColor()" />

    <x-device.stats.usage-card :label="$swapLabel" :value="$metric ? ($metric->pageFilePercentForHumans() ?? $missingSwap) : null" :detail="$metric?->pageFileForHumans()" :percent="$metric?->pageFilePercent()" :color="$metric?->pageFileBarColor()" data-swap-usage />

    @if($disk && $disk['usedPercent'] !== null)
        <x-device.stats.usage-card :label="'Disk '.$disk['name']" :value="$disk['usedForHumans']" :detail="$disk['freeForHumans']" :percent="$disk['usedPercent']" :color="$disk['barColor']" />
    @else
        <x-device.stats.usage-card label="Disk" />
    @endif

    <flux:card class="col-span-2 xl:col-span-1">
        <x-device.stats.stat label="Uptime" :value="$metric?->uptimeForHumans()" />
    </flux:card>
</div>
