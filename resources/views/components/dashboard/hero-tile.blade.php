@props(['label', 'value', 'href', 'valueClass' => 'text-zinc-900 dark:text-white'])

<div {{ $attributes->class('min-w-0') }}>
    <a href="{{ $href }}" wire:navigate class="block">
        <span class="block text-[11px] font-medium uppercase tracking-wider text-zinc-500">{{ $label }}</span>
        <span class="mt-2 block text-4xl font-semibold leading-none tabular-nums {{ $valueClass }}">{{ $value }}</span>
    </a>
    @isset($chart)
        <div class="mt-3">{{ $chart }}</div>
    @endisset
    @isset($aside)
        <div class="mt-1.5 text-xs text-zinc-400 dark:text-zinc-500">{{ $aside }}</div>
    @endisset
</div>
