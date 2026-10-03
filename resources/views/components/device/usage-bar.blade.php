@props(['percent', 'color'])

<div {{ $attributes->class('h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700') }}>
    <div class="h-full rounded-full transition-all {{ $color }}" style="width: {{ number_format($percent, 1) }}%"></div>
</div>
