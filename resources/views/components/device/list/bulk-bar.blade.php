@props(['groups', 'tags'])

<div
    x-data="{
        get count() { return $wire.selectedDevices.length },
        get devices() { return this.count + (this.count === 1 ? ' device' : ' devices') },
    }"
    x-show="count > 0"
    x-cloak
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 translate-y-3"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-3"
    class="pointer-events-none fixed inset-x-0 bottom-4 z-40 flex justify-center px-4 lg:start-64"
    data-bulk-bar
>
    <div role="toolbar" aria-label="Bulk actions" class="dark pointer-events-auto flex max-w-full items-center gap-0.5 rounded-full bg-zinc-900 py-1.5 ps-3 pe-1.5 sm:ps-4 max-sm:[&_[data-flux-button]]:px-2 text-white shadow-xl shadow-black/20 ring-1 ring-white/10">
        <span class="pe-2 text-sm font-medium whitespace-nowrap tabular-nums"><span x-text="count"></span> selected</span>
        <div class="me-1 h-5 w-px bg-white/15"></div>

        <flux:button size="sm" variant="ghost" icon="arrow-path" x-on:click="$dispatch('confirm-action', { heading: 'Restart devices', message: 'Restart ' + devices + '?', confirm: 'Restart', action: () => $wire.bulkRestart() })">
            <span class="max-sm:sr-only">Restart</span>
        </flux:button>
        <flux:button size="sm" variant="ghost" icon="power" class="text-red-400! hover:text-red-300!" x-on:click="$dispatch('confirm-action', { heading: 'Power off devices', message: 'Power off ' + devices + '?', confirm: 'Power off', danger: true, action: () => $wire.bulkPowerOff() })">
            <span class="max-sm:sr-only">Power off</span>
        </flux:button>
        <flux:button size="sm" variant="ghost" icon="code-bracket" wire:click="$set('showBulkScriptModal', true)">
            <span class="max-sm:sr-only">Run script</span>
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

        <div class="mx-1 h-5 w-px bg-white/15"></div>
        <flux:button size="sm" variant="ghost" icon="x-mark" square class="rounded-full!" x-on:click="$wire.selectedDevices = []; $wire.clearSelection()" aria-label="Clear selection" />
    </div>
</div>
