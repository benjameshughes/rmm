@props(['label', 'value' => null, 'detail' => null, 'percent' => null, 'color' => 'bg-blue-500'])

<flux:card {{ $attributes->class('space-y-2') }}>
    <x-device.stats.stat :label="$label" :value="$value" :detail="$detail" />
    @if($percent !== null)
        <x-device.usage-bar :percent="$percent" :color="$color" />
    @endif
</flux:card>
