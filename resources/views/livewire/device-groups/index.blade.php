<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Device Groups</flux:heading>
        <div class="flex items-center gap-2">
            <flux:button as="a" :href="route('tags.index')" wire:navigate variant="ghost">
                Manage Tags
            </flux:button>
            <flux:button wire:click="$set('showCreateModal', true)" variant="primary">
                New Group
            </flux:button>
        </div>
    </div>
    <flux:separator variant="subtle" />

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Description</flux:table.column>
                <flux:table.column>Devices</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($groups as $group)
                    <flux:table.row>
                        <flux:table.cell>
                            <flux:badge :color="$group->color ?? 'zinc'">{{ $group->name }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $group->description ?? '—' }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="font-medium">{{ $group->devices_count }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:button size="sm" variant="ghost" wire:click="edit({{ $group->id }})" icon="pencil-square">
                                    Edit
                                </flux:button>
                                <flux:button size="sm" variant="ghost" x-on:click="$dispatch('confirm-action', { heading: 'Delete group', message: {{ Js::from('Delete group \''.$group->name.'\'? Devices in this group will be unassigned.') }}, confirm: 'Delete', danger: true, action: () => $wire.delete({{ $group->id }}) })" icon="trash">
                                    Delete
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">No groups yet. Create one to organise your devices.</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showCreateModal" class="md:w-96" @cancel="resetForm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit Group' : 'New Group' }}</flux:heading>
            </div>

            <flux:input wire:model="name" label="Name" placeholder="e.g. Warehouse PCs" required />

            <flux:textarea wire:model="description" label="Description" placeholder="Optional description..." rows="2" />

            <flux:select wire:model="color" label="Colour">
                @foreach($colors as $c)
                    <flux:select.option value="{{ $c }}">{{ ucfirst($c) }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="resetForm" variant="ghost">Cancel</flux:button>
                @if($editingId)
                    <flux:button wire:click="update" variant="primary">Update</flux:button>
                @else
                    <flux:button wire:click="create" variant="primary">Create</flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</div>
