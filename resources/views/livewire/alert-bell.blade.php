<a href="{{ route('alerts.index') }}" class="relative inline-flex items-center" wire:navigate wire:poll.30s>
    <flux:icon name="bell" class="size-5 text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" />
    @if($count > 0)
        <span class="absolute -top-1.5 -right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">{{ $count > 99 ? '99+' : $count }}</span>
    @endif
</a>
