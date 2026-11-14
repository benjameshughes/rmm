@props(['groups', 'emptyMessage', 'showPlatform' => false])

<div {{ $attributes->class('space-y-4') }}>
    <flux:input wire:model.live.debounce.250ms="scriptSearch" icon="magnifying-glass" placeholder="Search by name or what it does..." clearable autofocus data-script-search />

    <div class="max-h-[60vh] space-y-5 overflow-y-auto pr-1">
        @forelse($groups as $group)
            <x-script.group :group="$group" wire:key="picker-group-{{ $group->category->value }}">
                @foreach($group->scripts as $script)
                    <button type="button" wire:click="selectScript({{ $script->id }})" wire:key="picker-script-{{ $script->id }}" class="block w-full text-left transition hover:bg-zinc-50 focus:bg-zinc-50 focus:outline-none dark:hover:bg-zinc-800 dark:focus:bg-zinc-800" data-picker-script="{{ $script->id }}">
                        <x-script.row :script="$script" :show-platform="$showPlatform" />
                    </button>
                @endforeach
            </x-script.group>
        @empty
            <div class="py-10 text-center">
                <flux:icon.magnifying-glass class="mx-auto mb-2 text-zinc-300 dark:text-zinc-600" />
                <flux:text>{{ $emptyMessage }}</flux:text>
            </div>
        @endforelse
    </div>
</div>
