@props(['event'])

<div {{ $attributes->class('flex flex-col gap-1 py-3 sm:flex-row sm:gap-4') }} x-data="{ expanded: false }">
    <div class="flex shrink-0 items-center gap-2 sm:w-44 sm:flex-col sm:items-start sm:gap-1">
        <flux:badge size="sm" :color="$event->levelColor()">{{ $event->level }}</flux:badge>
        <flux:text size="xs" class="tabular-nums" title="{{ $event->timeForHumans }}">{{ $event->time ?? 'Time unknown' }}</flux:text>
    </div>
    <div class="min-w-0 flex-1 space-y-1">
        <div class="flex flex-wrap items-baseline gap-x-2">
            <flux:text size="sm" class="font-medium text-zinc-800 dark:text-white">{{ $event->source ?? 'Unknown source' }}</flux:text>
            @if($event->eventId)
                <flux:text size="xs" class="font-mono">Event {{ $event->eventId }}</flux:text>
            @endif
        </div>
        @if($event->message)
            @if($event->hasLongMessage())
                <button type="button" class="block w-full text-start" x-on:click="expanded = ! expanded" :aria-expanded="expanded">
                    <flux:text size="sm" class="break-words" x-bind:class="expanded ? '' : 'line-clamp-1'">{{ $event->message }}</flux:text>
                    <flux:text size="xs" class="mt-1 text-zinc-500 underline dark:text-zinc-400" x-text="expanded ? 'Show less' : 'Show more'">Show more</flux:text>
                </button>
            @else
                <flux:text size="sm" class="break-words">{{ $event->message }}</flux:text>
            @endif
        @endif
    </div>
</div>
