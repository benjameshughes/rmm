<flux:dropdown position="bottom" align="end">
    <button type="button" class="relative inline-flex items-center" aria-label="Notifications">
        <flux:icon name="bell" class="size-5 text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" />
        @if($unreadCount > 0)
            <span class="absolute -top-1.5 -right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>

    <flux:menu class="w-80">
        <div class="flex items-center justify-between px-2 py-1.5">
            <flux:heading size="sm">Notifications</flux:heading>
            @if($unreadCount > 0)
                <flux:button size="xs" variant="ghost" wire:click="markAllAsRead">Mark all read</flux:button>
            @endif
        </div>

        <flux:menu.separator />

        @forelse($notifications as $notification)
            <flux:menu.item wire:key="notification-{{ $notification->id }}" wire:click="open('{{ $notification->id }}')" class="items-start! gap-2">
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <div class="flex items-center gap-2">
                        @if($notification->isUnread)
                            <span class="size-2 shrink-0 rounded-full bg-blue-500"></span>
                        @endif
                        <span class="truncate font-medium">{{ $notification->title }}</span>
                        <flux:badge size="sm" :color="$notification->level->color()" class="ms-auto">{{ $notification->level->label() }}</flux:badge>
                    </div>
                    <span class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $notification->body }}</span>
                    <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $notification->createdAt->diffForHumans() }}</span>
                </div>
            </flux:menu.item>
        @empty
            <div class="px-2 py-4 text-center text-sm text-zinc-500 dark:text-zinc-400">No notifications</div>
        @endforelse

        <flux:menu.separator />

        <flux:menu.item icon="bell-alert" :href="route('alerts.index')" wire:navigate>View all alerts</flux:menu.item>
    </flux:menu>
</flux:dropdown>
