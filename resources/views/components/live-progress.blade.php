{{-- A Flux progress bar that keeps up with live updates. Flux only reads the value when the bar boots and a Livewire morph won't change it, so each new figure gets a fresh bar via wire:key, and the width is rendered up front so it is right before Flux boots. Flux has no zinc, so a neutral bar sets its own colour. --}}
@props(['value' => null, 'color' => null, 'key' => 'progress'])

@php($percent = round((float) $value, 1))

<flux:progress
    {{ $attributes->merge([
        'role' => 'progressbar',
        'aria-valuemin' => '0',
        'aria-valuemax' => '100',
        'aria-valuenow' => $percent,
    ])->class([
        '[--flux-progress-color:var(--color-zinc-400)]! dark:[--flux-progress-color:var(--color-zinc-500)]!' => $color === 'zinc',
    ]) }}
    wire:key="{{ $key }}-{{ $percent }}"
    :value="$percent"
    :color="$color"
    style="--flux-progress-percentage: {{ $percent }}%"
/>
