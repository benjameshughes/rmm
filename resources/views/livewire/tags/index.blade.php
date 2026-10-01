<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Tags</flux:heading>
        <div class="flex items-center gap-2">
            <flux:button as="a" :href="route('device-groups.index')" wire:navigate variant="ghost">
                Manage Groups
            </flux:button>
            <flux:button wire:click="$set('showCreateModal', true)" variant="primary">
                New Tag
            </flux:button>
        </div>
    </div>
    <flux:separator variant="subtle" />

    <flux:card>
        <div class="flex flex-wrap gap-3">
            @forelse ($tags as $tag)
                <div class="flex items-center gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2">
                    <flux:badge :color="$tag->color ?? 'zinc'">{{ $tag->name }}</flux:badge>
                    <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">{{ $tag->devices_count }} devices</flux:text>
                    <flux:button size="sm" variant="ghost" wire:click="delete({{ $tag->id }})" wire:confirm="Delete tag '{{ $tag->name }}'?" icon="x-mark" square class="!p-0.5" />
                </div>
            @empty
                <div class="w-full text-center py-8 text-zinc-500 dark:text-zinc-400">No tags yet. Create one to label your devices.</div>
            @endforelse
        </div>
    </flux:card>

    <flux:modal wire:model="showCreateModal" class="md:w-96">
        <div class="space-y-6">
            <flux:heading size="lg">New Tag</flux:heading>

            <flux:input wire:model="name" label="Name" placeholder="e.g. production" required />

            <flux:select wire:model="color" label="Colour">
                @foreach($colors as $c)
                    <flux:select.option value="{{ $c }}">{{ ucfirst($c) }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="create" variant="primary">Create</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
