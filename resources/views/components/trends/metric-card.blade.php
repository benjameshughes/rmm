@props(['change', 'compact' => false])

@php($direction = $change->direction())

<flux:card {{ $attributes->class(['space-y-3', 'p-4!' => $compact]) }} data-trend-metric="{{ $change->metric->value }}">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <flux:text size="sm" class="truncate font-medium text-zinc-800 dark:text-white">{{ $change->metric->label() }}</flux:text>
            <flux:text size="xs" class="truncate">{{ $change->metric->fleetDescription() }}</flux:text>
        </div>
        <x-trends.change-pill :change="$change" />
    </div>

    <div class="flex flex-wrap items-baseline gap-x-2 tabular-nums">
        @if($change->isMeasured())
            <flux:text class="text-zinc-400 dark:text-zinc-500">{{ $change->previousForHumans() }}</flux:text>
            <flux:icon name="arrow-right" variant="micro" class="self-center text-zinc-400 dark:text-zinc-500" />
        @endif
        <flux:heading :size="$compact ? 'lg' : 'xl'">{{ $change->currentForHumans() ?? '—' }}</flux:heading>
    </div>

    @unless($compact)
        <div class="space-y-1.5">
            <div class="grid grid-cols-[3rem_1fr] items-center gap-2">
                <flux:text size="xs">Before</flux:text>
                <x-device.usage-bar :percent="$change->previousShare()" color="zinc" thin />
            </div>
            <div class="grid grid-cols-[3rem_1fr] items-center gap-2">
                <flux:text size="xs">Now</flux:text>
                <x-device.usage-bar :percent="$change->currentShare()" :color="$direction->color()" thin />
            </div>
        </div>
    @endunless
</flux:card>
