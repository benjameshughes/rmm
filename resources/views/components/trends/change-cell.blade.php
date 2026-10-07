@props(['change'])

<div class="flex flex-col items-end gap-1">
    <flux:text size="sm" class="whitespace-nowrap tabular-nums">
        @if($change->isMeasured())
            <span class="text-zinc-400 dark:text-zinc-500">{{ $change->previousForHumans() }}</span> &rarr;
        @endif
        {{ $change->currentForHumans() ?? '—' }}
    </flux:text>
    <x-trends.change-pill :change="$change" />
</div>
