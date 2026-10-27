@props(['events', 'moreEvents', 'status', 'window'])

<x-dashboard.section id="system-errors" class="scroll-mt-6" title="System errors" :description="$window">
    @if($events->isNotEmpty())
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800" x-data="{ showAll: false }" data-system-errors>
            @foreach($events as $event)
                <x-inventory.event :event="$event" wire:key="system-error-{{ $loop->index }}" />
            @endforeach
            @foreach($moreEvents as $event)
                <x-inventory.event :event="$event" x-show="showAll" x-cloak wire:key="system-error-more-{{ $loop->index }}" />
            @endforeach
            @if($moreEvents->isNotEmpty())
                <div class="pt-3">
                    <flux:button size="sm" variant="ghost" x-on:click="showAll = ! showAll" x-text="showAll ? 'Show fewer' : 'Show {{ $moreEvents->count() }} more'" data-system-errors-more>Show {{ $moreEvents->count() }} more</flux:button>
                </div>
            @endif
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
