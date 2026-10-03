@props(['label', 'value' => null, 'percent' => null, 'color' => 'bg-blue-500'])

<div {{ $attributes->class('grid grid-cols-[2.75rem_1fr_3rem] items-center gap-2') }}>
    <flux:text size="xs" class="truncate">{{ $label }}</flux:text>
    @if($percent !== null)
        <x-device.usage-bar :percent="$percent" :color="$color" thin />
    @else
        <div class="h-1.5 rounded-full bg-zinc-100 dark:bg-zinc-700/50"></div>
    @endif
    <flux:text size="xs" class="text-right tabular-nums">{{ $value ?? '—' }}</flux:text>
</div>
