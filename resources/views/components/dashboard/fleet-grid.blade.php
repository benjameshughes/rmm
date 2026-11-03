@props(['groups'])

<div {{ $attributes->class('flex flex-wrap gap-x-12 gap-y-3') }} data-fleet-grid>
    @foreach($groups as $group)
        <div class="flex min-w-0 items-start gap-3" wire:key="fleet-grid-{{ $group->name }}">
            <span class="w-20 shrink-0 pt-0.5 text-xs text-zinc-500" data-fleet-group-label>{{ $group->label() }}</span>
            <div class="flex max-w-2xl flex-wrap gap-1">
                @foreach($group->squares as $square)
                    <flux:tooltip wire:key="fleet-square-{{ $square->device->id }}">
                        <a href="{{ route('devices.show', $square->device) }}" wire:navigate class="block size-5 {{ $square->classes() }} hover:outline hover:outline-1 hover:outline-zinc-900 dark:hover:outline-white" data-fleet-square aria-label="{{ $square->device->hostname }}"></a>
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
