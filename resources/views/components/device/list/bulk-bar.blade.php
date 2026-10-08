@props(['groups', 'tags'])

<x-bulk-bar selection="selectedDevices" noun="device">
    <flux:button size="sm" variant="ghost" icon="arrow-path" x-on:click="$dispatch('confirm-action', { heading: 'Restart devices', message: 'Restart ' + countLabel + '?', confirm: 'Restart', action: () => $wire.bulkRestart() })">
        <span class="max-sm:sr-only">Restart</span>
    </flux:button>
    <flux:button size="sm" variant="ghost" icon="power" class="text-red-400! hover:text-red-300!" x-on:click="$dispatch('confirm-action', { heading: 'Power off devices', message: 'Power off ' + countLabel + '?', confirm: 'Power off', danger: true, action: () => $wire.bulkPowerOff() })">
        <span class="max-sm:sr-only">Power off</span>
    </flux:button>
    <flux:button size="sm" variant="ghost" icon="arrow-up-circle" x-on:click="$dispatch('confirm-action', { heading: 'Update agents', message: 'Update the agent on ' + countLabel + ' to the latest release?', confirm: 'Update', action: () => $wire.bulkUpdateAgent() })" data-bulk-update-agent>
        <span class="max-sm:sr-only">Update agent</span>
    </flux:button>
    <flux:button size="sm" variant="ghost" icon="code-bracket" wire:click="$set('showBulkScriptModal', true)">
        <span class="max-sm:sr-only">Run script</span>
    </flux:button>
    <flux:button size="sm" variant="ghost" icon="arrow-down-tray" x-on:click="$dispatch('open-install-software', { deviceIds: $wire.selectedDevices })" data-bulk-install>
        <span class="max-sm:sr-only">Install software</span>
    </flux:button>

    <flux:dropdown position="top" align="center">
        <flux:button size="sm" variant="ghost" icon="folder" data-bulk-group>
            <span class="max-sm:sr-only">Group</span>
        </flux:button>
        <flux:menu>
            @foreach($groups as $group)
                <flux:menu.item wire:click="bulkAssignGroup({{ $group->id }})" wire:key="bulk-group-{{ $group->id }}">Move to {{ $group->name }}</flux:menu.item>
            @endforeach
            <flux:menu.separator />
            <flux:menu.item wire:click="bulkAssignGroup(null)" icon="x-mark">Remove from group</flux:menu.item>
        </flux:menu>
    </flux:dropdown>

    <flux:dropdown position="top" align="end">
        <flux:button size="sm" variant="ghost" icon="tag" data-bulk-tags>
            <span class="max-sm:sr-only">Tags</span>
        </flux:button>
        <flux:menu>
            <flux:menu.submenu heading="Add tag">
                @foreach($tags as $tag)
                    <flux:menu.item wire:click="bulkAddTag({{ $tag->id }})" wire:key="bulk-add-tag-{{ $tag->id }}">{{ $tag->name }}</flux:menu.item>
                @endforeach
            </flux:menu.submenu>
            <flux:menu.submenu heading="Remove tag">
                @foreach($tags as $tag)
                    <flux:menu.item wire:click="bulkRemoveTag({{ $tag->id }})" wire:key="bulk-remove-tag-{{ $tag->id }}">{{ $tag->name }}</flux:menu.item>
                @endforeach
            </flux:menu.submenu>
        </flux:menu>
    </flux:dropdown>
</x-bulk-bar>
