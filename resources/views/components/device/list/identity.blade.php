@props(['device', 'showGroup' => true, 'href' => null])

<div {{ $attributes->class('min-w-0 space-y-1') }}>
    <a href="{{ $href ?? route('devices.show', $device) }}" wire:navigate class="block truncate font-semibold text-zinc-800 hover:underline dark:text-white">{{ $device->hostname }}</a>
    <flux:text size="xs" class="truncate">
        {{ $device->operatingSystem() ?? 'Unknown OS' }}
        @if($device->last_ip)
            &middot; <span class="font-mono">{{ $device->last_ip }}</span>
        @endif
    </flux:text>
    @if(($showGroup && $device->group) || $device->tags->isNotEmpty())
        <div class="flex flex-wrap gap-1">
            @if($showGroup && $device->group)
                <flux:badge size="sm" :color="$device->group->color ?? 'zinc'">{{ $device->group->name }}</flux:badge>
            @endif
            @foreach($device->tags as $tag)
                <flux:badge size="sm" :color="$tag->color ?? 'zinc'" variant="outline" wire:key="device-{{ $device->id }}-tag-{{ $tag->id }}">{{ $tag->name }}</flux:badge>
            @endforeach
        </div>
    @endif
</div>
