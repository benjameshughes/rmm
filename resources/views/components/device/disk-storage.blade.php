@props(['disks' => null, 'busy' => null])

@if(filled($disks) || $busy)
    <flux:card {{ $attributes }}>
        <div class="mb-4 flex items-center justify-between gap-4">
            <flux:heading size="sm">Disk Storage</flux:heading>
            @if($busy)
                <flux:text size="sm" data-disk-busy>{{ $busy }} busy</flux:text>
            @endif
        </div>
        <div class="space-y-4">
            @foreach($disks as $disk)
                <div wire:key="disk-{{ $disk['name'] }}">
                    <div class="mb-1 flex items-center justify-between gap-4">
                        <div>
                            <flux:text class="font-medium">{{ $disk['name'] }}</flux:text>
                            @if($disk['mountPoint'])
                                <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">{{ $disk['mountPoint'] }}</flux:text>
                            @endif
                        </div>
                        @if($disk['freeForHumans'])
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $disk['freeForHumans'] }}</flux:text>
                        @endif
                    </div>
                    @if($disk['usedPercent'] !== null)
                        <x-device.usage-bar :percent="$disk['usedPercent']" :color="$disk['barColor']" />
                        <flux:text size="xs" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ $disk['usedForHumans'] }} used</flux:text>
                    @endif
                    @if($disk['inodeForHumans'])
                        <flux:text size="xs" class="{{ $disk['inodeColor'] }}" data-inode-usage>{{ $disk['inodeForHumans'] }}</flux:text>
                    @endif
                </div>
            @endforeach
        </div>
    </flux:card>
@endif
