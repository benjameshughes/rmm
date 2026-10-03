@props(['metric' => null, 'swapLabel' => 'Page File', 'missingSwap' => '—'])

@if($metric?->hasPerformanceData)
    <flux:card {{ $attributes }}>
        <flux:heading size="sm" class="mb-4">Performance right now</flux:heading>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-device.stats.stat :label="$swapLabel" :value="$metric->pageFilePercentForHumans() ?? $missingSwap" :detail="$metric->pageFileForHumans()" />
            <x-device.stats.stat label="Disk Busy" :value="$metric->diskBusyForHumans()" detail="busiest physical disk" />
        </div>
    </flux:card>
@endif
