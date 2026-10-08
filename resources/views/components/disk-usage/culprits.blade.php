@props(['culprits', 'nodeUrl'])

<x-dashboard.section title="Space hogs" description="Well-known folders and files that fill drives." {{ $attributes }}>
    @if($culprits->isEmpty())
        <flux:text size="sm" data-culprits-empty>None found in this scan.</flux:text>
    @else
        <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
            @foreach($culprits as $culprit)
                <div class="flex items-center justify-between gap-3 py-2" wire:key="culprit-{{ $loop->index }}" data-culprit="{{ $culprit->label }}">
                    <div class="min-w-0">
                        @if($culprit->indexPath !== null)
                            <a href="{{ $nodeUrl($culprit->indexPath) }}" wire:click.prevent="open('{{ $culprit->indexPath }}')" class="text-sm font-medium text-zinc-800 hover:underline dark:text-white">{{ $culprit->label }}</a>
                        @else
                            <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ $culprit->label }}</span>
                        @endif
                        @if($culprit->owner)
                            <flux:text size="xs" class="truncate">{{ $culprit->owner }}</flux:text>
                        @endif
                    </div>
                    <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-300">{{ $culprit->allocatedForHumans() }}</span>
                </div>
            @endforeach
        </div>
    @endif
</x-dashboard.section>
