@props(['label', 'value', 'icon', 'href' => null, 'active' => false, 'tone' => 'text-zinc-400 dark:text-zinc-500'])

@php($classes = [
    'flex w-full items-center gap-3 rounded-xl border p-4 text-start transition',
    'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600' => ! $active,
    'border-zinc-800 bg-zinc-50 ring-1 ring-zinc-800 dark:border-white dark:bg-white/5 dark:ring-white' => $active,
])

@if($href)
    <a href="{{ $href }}" wire:navigate {{ $attributes->class($classes) }}>
        <flux:icon :name="$icon" variant="mini" class="shrink-0 {{ $tone }}" />
        <div class="min-w-0">
            <flux:text size="sm" class="truncate">{{ $label }}</flux:text>
            <flux:heading size="lg" class="tabular-nums">{{ $value }}</flux:heading>
        </div>
    </a>
@else
    <button type="button" aria-pressed="{{ $active ? 'true' : 'false' }}" {{ $attributes->class($classes) }}>
        <flux:icon :name="$icon" variant="mini" class="shrink-0 {{ $tone }}" />
        <div class="min-w-0">
            <flux:text size="sm" class="truncate">{{ $label }}</flux:text>
            <flux:heading size="lg" class="tabular-nums">{{ $value }}</flux:heading>
        </div>
    </button>
@endif
