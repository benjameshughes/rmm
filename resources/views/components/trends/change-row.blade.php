@props(['change'])

@php($summary = $change->summary())

<div {{ $attributes->class('flex gap-3 py-3') }} data-trend-change="{{ $change->key }}">
    <flux:icon :name="$change->kind->icon()" variant="mini" class="mt-0.5 shrink-0 {{ $change->kind->color() }}" />

    <div class="min-w-0 flex-1 space-y-1.5">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            @if($change->href)
                <a href="{{ $change->href }}" wire:navigate class="text-sm font-medium text-zinc-800 hover:underline dark:text-white">{{ $summary }}</a>
            @else
                <flux:text size="sm" class="font-medium text-zinc-800 dark:text-white">{{ $summary }}</flux:text>
            @endif
            @if($change->isScheduled)
                <flux:badge size="sm" color="zinc" icon="calendar">Scheduled</flux:badge>
            @endif
        </div>

        <flux:text size="xs">{{ $change->runsForHumans() }} &middot; {{ $change->datesForHumans() }}</flux:text>

        <div class="flex flex-wrap items-center gap-1">
            @foreach($change->visibleCommands() as $command)
                <button type="button" class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-700 hover:bg-zinc-200 dark:bg-white/10 dark:text-zinc-200 dark:hover:bg-white/20" wire:click="$dispatch('show-command', { commandId: {{ $command->id }} })" wire:key="change-{{ $change->key }}-command-{{ $command->id }}">{{ $command->device->hostname }}</button>
            @endforeach
            @php($hiddenDevices = $change->hiddenDeviceCount())
            @if($hiddenDevices > 0)
                <flux:text size="xs">+{{ $hiddenDevices }} more</flux:text>
            @endif
        </div>
    </div>
</div>
