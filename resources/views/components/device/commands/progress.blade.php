{{-- How far a running command has got. Without a progress report yet (or one with no figures) the bar pulses instead. Drop the script's message where a heading already says what is running. --}}
@props(['progress' => null, 'withMessage' => true])

@php($percent = $progress?->percent())

<div {{ $attributes->class('min-w-0 space-y-1.5') }} data-command-progress>
    <x-live-progress
        key="command-progress"
        :value="$percent ?? 100"
        color="blue"
        :class="$percent === null ? 'animate-pulse' : null"
        :aria-label="$progress?->message ?? 'Progress'"
        :aria-valuenow="$percent === null ? null : round($percent, 1)"
        :aria-valuetext="$progress?->label() ?: null"
    />

    @if($progress)
        <div class="flex flex-wrap items-baseline gap-x-2 text-sm tabular-nums">
            @if($withMessage && $progress->message)
                <span class="font-medium text-zinc-800 dark:text-white">{{ $progress->message }}</span>
            @endif
            <span class="text-zinc-500 dark:text-zinc-400" data-command-progress-label>{{ $progress->label() }}</span>
        </div>

        @if($current = $progress->currentForDisplay())
            <p class="truncate font-mono text-xs text-zinc-500 dark:text-zinc-400" title="{{ $progress->current }}" data-command-progress-current>{{ $current }}</p>
        @endif
    @endif
</div>
