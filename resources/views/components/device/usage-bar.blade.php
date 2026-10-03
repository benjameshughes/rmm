@props(['percent', 'color', 'thin' => false])

<div {{ $attributes->class(['overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700', 'h-1.5' => $thin, 'h-2' => ! $thin]) }}>
    <div class="h-full rounded-full transition-all {{ $color }}" style="width: {{ number_format($percent, 1) }}%"></div>
</div>
