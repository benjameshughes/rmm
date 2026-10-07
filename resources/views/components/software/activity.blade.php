@props(['commands' => null])

@if($commands?->isNotEmpty())
    <button type="button" class="flex min-w-0 items-center gap-1 text-start text-xs text-sky-700 hover:underline dark:text-sky-300" wire:click="$dispatch('show-command', { commandId: {{ $commands->first()->id }} })" data-software-activity>
        <flux:icon.loading variant="micro" class="shrink-0" />
        <span class="truncate">{{ $commands->first()->activityLabel() }} on {{ $commands->count() }} {{ Str::plural('device', $commands->count()) }}</span>
    </button>
@endif
