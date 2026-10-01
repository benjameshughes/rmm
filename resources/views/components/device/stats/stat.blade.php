@props(['label', 'value' => null, 'detail' => null])

<div {{ $attributes->class('space-y-1') }}>
    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $label }}</flux:text>
    <flux:heading size="xl">{{ $value ?? '—' }}</flux:heading>
    @if($detail)
        <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">{{ $detail }}</flux:text>
    @endif
</div>
