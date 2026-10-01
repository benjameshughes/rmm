@props(['metric' => null])

@if($metric?->hasPerformanceData)
    <flux:card {{ $attributes }}>
        <flux:heading size="sm" class="mb-4">Performance</flux:heading>
        <div class="grid gap-4 sm:grid-cols-3">
            <x-device.stats.stat label="CPU Queue" :value="$metric->cpuQueueForHumans()" detail="threads waiting for a CPU" />
            <x-device.stats.stat label="Page File" :value="$metric->pageFilePercentForHumans()" :detail="$metric->pageFileForHumans()" />
            <x-device.stats.stat label="Disk Busy" :value="$metric->diskBusyForHumans()" detail="busiest physical disk" />
        </div>
    </flux:card>
@endif
