@props(['label', 'value', 'dot' => 'bg-zinc-400', 'href' => null, 'active' => false])

@php($classes = [
    'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm transition',
    'border-zinc-200 text-zinc-600 hover:border-zinc-300 hover:text-zinc-800 dark:border-zinc-700 dark:text-zinc-300 dark:hover:border-zinc-600 dark:hover:text-white' => ! $active,
    'border-zinc-800 bg-zinc-800 text-white dark:border-white dark:bg-white dark:text-zinc-900' => $active,
])

@if($href)
    <a href="{{ $href }}" wire:navigate {{ $attributes->class($classes) }}>
        <span class="size-2 rounded-full {{ $dot }}"></span>
        <span>{{ $label }}</span>
        <span class="font-semibold tabular-nums">{{ $value }}</span>
    </a>
@else
    <button type="button" aria-pressed="{{ $active ? 'true' : 'false' }}" {{ $attributes->class($classes) }}>
        <span class="size-2 rounded-full {{ $dot }}"></span>
        <span>{{ $label }}</span>
        <span class="font-semibold tabular-nums">{{ $value }}</span>
    </button>
@endif
