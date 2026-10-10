@props(['percent', 'color', 'thin' => false])

<x-live-progress key="usage-bar" :value="$percent" :color="$color" {{ $attributes->class(['h-2' => ! $thin]) }} />
