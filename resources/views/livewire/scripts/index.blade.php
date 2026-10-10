<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Scripts</flux:heading>
        <flux:button as="a" :href="route('scripts.create')" wire:navigate variant="primary">
            New Script
        </flux:button>
    </div>
    <flux:separator variant="subtle" />

    <div class="flex flex-wrap items-center gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search scripts..." class="max-w-sm" icon="magnifying-glass" />
        <flux:select wire:model.live="categoryFilter" placeholder="All Categories" class="max-w-48">
            <flux:select.option value="">All Categories</flux:select.option>
            @foreach($categories as $category)
                <flux:select.option value="{{ $category->value }}">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="platformFilter" placeholder="All Platforms" class="max-w-48">
            <flux:select.option value="">All Platforms</flux:select.option>
            @foreach($platforms as $platform)
                <flux:select.option value="{{ $platform->value }}">{{ $platform->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:card>
        <flux:table :paginate="$scripts">
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Category</flux:table.column>
                <flux:table.column>Platform</flux:table.column>
                <flux:table.column>Type</flux:table.column>
                <flux:table.column>Timeout</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($scripts as $script)
                    <flux:table.row>
                        <flux:table.cell>
                            <div>
                                <flux:text class="font-medium">{{ $script->name }}</flux:text>
                                @if($script->is_system)
                                    <flux:badge size="sm" color="blue">System</flux:badge>
                                @endif
                                @if($script->is_internal)
                                    <flux:badge size="sm" color="zinc" data-internal-badge>Internal</flux:badge>
                                @endif
                                @if($script->description)
                                    <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">{{ Str::limit($script->description, 60) }}</flux:text>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" color="zinc">{{ $script->category->name }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" color="zinc">{{ $script->platform->name }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" color="zinc">{{ $script->script_type->name }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $script->timeout_seconds }}s</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:button as="a" size="sm" variant="ghost" :href="route('scripts.show', $script)" wire:navigate>
                                    View
                                </flux:button>
                                @unless($script->is_system)
                                    <flux:dropdown>
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" square aria-label="Actions" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil-square" :href="route('scripts.edit', $script)" wire:navigate>
                                                Edit
                                            </flux:menu.item>
                                            <flux:menu.item icon="trash" x-on:click="$dispatch('confirm-action', { heading: 'Delete script', message: {{ Js::from('Delete \''.$script->name.'\'? This cannot be undone.') }}, confirm: 'Delete', danger: true, action: () => $wire.delete({{ $script->id }}) })" variant="danger">
                                                Delete
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                @endunless
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">No scripts found</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
