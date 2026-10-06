@props(['device', 'commands'])

<flux:card {{ $attributes->class('space-y-4') }}>
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="sm">Recent Commands</flux:heading>
        <flux:button size="sm" variant="ghost" icon:trailing="arrow-right" :href="route('devices.commands', $device)" wire:navigate>All commands</flux:button>
    </div>

    @forelse($commands as $command)
        <div class="flex cursor-pointer items-center justify-between gap-4 rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-white/5" wire:key="command-{{ $command->id }}" wire:click="$dispatch('show-command', { commandId: {{ $command->id }} })">
            <div class="min-w-0">
                <flux:text class="truncate font-medium text-zinc-800 dark:text-white">{{ $command->displayName() }}</flux:text>
                <flux:text size="sm">{{ $command->queued_at->diffForHumans() }}</flux:text>
            </div>
            <x-device.commands.status :command="$command" />
        </div>
    @empty
        <flux:text>No commands run on this device yet.</flux:text>
    @endforelse
</flux:card>
