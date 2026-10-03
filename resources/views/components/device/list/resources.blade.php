@props(['metric' => null, 'disk' => null])

<div {{ $attributes->class('w-44 space-y-1') }}>
    <x-device.usage-meter label="CPU" :value="$metric?->cpuRoundedForHumans()" :percent="$metric?->cpu" :color="$metric?->cpuBarColor() ?? 'bg-blue-500'" />
    <x-device.usage-meter label="RAM" :value="$metric?->ramRoundedForHumans()" :percent="$metric?->ram" :color="$metric?->ramBarColor() ?? 'bg-blue-500'" />
    <x-device.usage-meter :label="$disk['name'] ?? 'Disk'" :value="$disk['usedForHumans'] ?? null" :percent="$disk['usedPercent'] ?? null" :color="$disk['barColor'] ?? 'bg-blue-500'" />
</div>
