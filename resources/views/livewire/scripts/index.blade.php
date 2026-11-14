<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Scripts</flux:heading>
            <flux:text class="mt-1">{{ $summary }}</flux:text>
        </div>
        <flux:button as="a" :href="route('scripts.create')" wire:navigate variant="primary" icon="plus">New Script</flux:button>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <flux:input wire:model.live.debounce.250ms="search" placeholder="Search by name or what it does..." icon="magnifying-glass" clearable class="sm:max-w-md" />
        <div class="flex gap-3">
            <flux:select wire:model.live="categoryFilter" class="sm:w-48">
                <flux:select.option value="">All categories</flux:select.option>
                @foreach($categories as $category)
                    <flux:select.option value="{{ $category->value }}">{{ $category->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="platformFilter" class="sm:w-44">
                <flux:select.option value="">All platforms</flux:select.option>
                @foreach($platforms as $platform)
                    <flux:select.option value="{{ $platform->value }}">{{ $platform->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <div class="space-y-8">
        @forelse($groups as $group)
            <x-script.group :group="$group" wire:key="library-group-{{ $group->category->value }}">
                @foreach($group->scripts as $script)
                    <x-script.row :script="$script" wire:key="library-script-{{ $script->id }}">
                        <x-slot:actions>
                            @unless($script->is_internal)
                                <flux:button size="sm" icon="play" wire:click="openRun({{ $script->id }})" data-run-script="{{ $script->id }}">Run</flux:button>
                            @endunless
                            <flux:button as="a" size="sm" variant="ghost" :href="route('scripts.show', $script)" wire:navigate>View</flux:button>
                            @unless($script->is_system)
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" square aria-label="More actions" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" :href="route('scripts.edit', $script)" wire:navigate>Edit</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" x-on:click="$dispatch('confirm-action', { heading: 'Delete script', message: {{ Js::from('Delete \''.$script->name.'\'? This cannot be undone.') }}, confirm: 'Delete', danger: true, action: () => $wire.delete({{ $script->id }}) })">Delete</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endunless
                        </x-slot:actions>
                    </x-script.row>
                @endforeach
            </x-script.group>
        @empty
            <flux:card class="py-12 text-center">
                <flux:icon.code-bracket class="mx-auto mb-3 text-zinc-300 dark:text-zinc-600" />
                <flux:heading>No scripts found</flux:heading>
                @if($isFiltered)
                    <flux:button size="sm" variant="ghost" class="mt-3" wire:click="clearFilters">Clear filters</flux:button>
                @endif
            </flux:card>
        @endforelse
    </div>

    <x-script.run-modal :script="$runScript" :devices="$availableDevices" :groups="$runGroups" />
</div>
