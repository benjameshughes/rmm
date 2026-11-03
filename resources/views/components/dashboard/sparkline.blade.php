@props(['points', 'field', 'color' => 'text-zinc-400'])

<flux:chart :value="$points" {{ $attributes->class('h-6 w-full') }}>
    <flux:chart.svg gutter="0">
        <flux:chart.line :field="$field" class="{{ $color }}" curve="none" />
    </flux:chart.svg>
</flux:chart>
