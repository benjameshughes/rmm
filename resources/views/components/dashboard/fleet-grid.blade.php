@props(['groups'])

<div {{ $attributes->class('flex flex-wrap gap-x-16 gap-y-6 py-4') }} data-fleet-grid>
    @foreach($groups as $group)
        <div class="flex min-w-64 basis-0 flex-col gap-3" style="flex-grow: {{ $group->count() }}" wire:key="fleet-grid-{{ $group->name }}">
            <span class="text-xs text-zinc-500" data-fleet-group-label>{{ $group->label() }}</span>
            <div class="grid grid-cols-[repeat(auto-fill,minmax(1.75rem,1fr))] gap-y-2">
                @foreach($group->squares as $square)
                    <flux:tooltip wire:key="fleet-square-{{ $square->device->id }}">
                        <a href="{{ route('devices.show', $square->device) }}" wire:navigate @class(['block size-5 justify-self-center', $square->signal->squareClass(), 'rounded-full' => $square->isMonitorOnly, 'rounded-[3px]' => ! $square->isMonitorOnly]) data-fleet-square data-fleet-signal="{{ $square->signal->value }}" aria-label="{{ $square->device->hostname }}"></a>
                        <flux:tooltip.content class="text-start">
                            <p class="font-semibold">{{ $square->device->hostname }}</p>
                            <p>{{ $square->statusLabel }}</p>
                            @if($square->needsAttention)
                                <p class="text-amber-300">Needs attention</p>
                            @endif
                            <p class="tabular-nums">CPU {{ $square->cpu ?? '—' }} · RAM {{ $square->ram ?? '—' }}</p>
                            @if($square->worstDisk)
                                <p class="tabular-nums">Disk {{ $square->worstDisk }}</p>
                            @endif
                            <p class="opacity-70">{{ $square->lastSeen }}</p>
                        </flux:tooltip.content>
                    </flux:tooltip>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
