@props(['check', 'href', 'detail' => null])

<a href="{{ $href }}" {{ $attributes->class('flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600') }} data-status-tile="{{ $check->color }}">
    <flux:icon :name="$check->icon()" variant="mini" class="shrink-0 {{ $check->tone() }}" />
    <div class="min-w-0">
        <flux:text size="sm" class="truncate">{{ $check->label }}</flux:text>
        <flux:heading size="lg" class="tabular-nums">{{ $check->value }}</flux:heading>
        @if($detail)
            <flux:text size="xs" class="truncate">{{ $detail }}</flux:text>
        @endif
    </div>
</a>
