@props(['disks' => null])

@if(filled($disks))
    <flux:card {{ $attributes }}>
        <flux:heading size="sm" class="mb-4">Disk Storage</flux:heading>
        <div class="space-y-4">
            @foreach($disks as $disk)
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <div>
                            <flux:text class="font-medium">{{ $disk['name'] }}</flux:text>
                            @if($disk['mountPoint'])
                                <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">{{ $disk['mountPoint'] }}</flux:text>
                            @endif
                        </div>
                        @if($disk['totalGb'] !== null && $disk['availableGb'] !== null)
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                {{ number_format($disk['availableGb'], 1) }} GB free of {{ number_format($disk['totalGb'], 1) }} GB
                            </flux:text>
                        @endif
                    </div>
                    @if($disk['usedPercent'] !== null)
                        <div class="h-2 bg-zinc-200 dark:bg-zinc-700 rounded-full overflow-hidden">
                            <div class="h-full {{ $disk['barColor'] }} rounded-full transition-all" style="width: {{ number_format($disk['usedPercent'], 1) }}%"></div>
                        </div>
                        <flux:text size="xs" class="mt-1 text-zinc-500 dark:text-zinc-400">
                            {{ number_format($disk['usedPercent'], 1) }}% used
                        </flux:text>
                    @endif
                </div>
            @endforeach
        </div>
    </flux:card>
@endif
