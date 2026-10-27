@props(['events', 'status', 'window'])

<x-dashboard.section id="system-errors" class="scroll-mt-6" title="System errors" :description="$window">
    @if($events->isNotEmpty())
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800" data-system-errors>
            @foreach($events as $event)
                <x-inventory.event :event="$event" wire:key="system-error-{{ $loop->index }}" />
            @endforeach
        </div>
    @elseif($status->color === 'green')
        <div class="flex items-center gap-2" data-system-errors-clear>
            <flux:icon :name="$status->icon()" variant="mini" class="{{ $status->tone() }}" />
            <flux:text size="sm">No errors. The System log is clean.</flux:text>
        </div>
    @else
        <flux:text size="sm">Not reported. Run the inventory again to check.</flux:text>
    @endif
</x-dashboard.section>
