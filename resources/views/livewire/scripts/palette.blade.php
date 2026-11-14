<div x-on:keydown.meta.k.window.prevent="$wire.open()" x-on:keydown.ctrl.k.window.prevent="$wire.open()">
    <flux:modal wire:model="showPalette" variant="bare" class="w-full max-w-xl my-[12vh] max-h-screen overflow-y-hidden">
        <flux:command filter="manual" class="border-none shadow-lg">
            <flux:command.input wire:model.live.debounce.150ms="paletteSearch" :placeholder="$scopeLabel" closable autofocus data-palette-search />

            <flux:command.items class="max-h-[50vh]">
                @foreach($scripts as $script)
                    <flux:command.item wire:click="pick({{ $script->id }})" wire:key="palette-script-{{ $script->id }}" icon="code-bracket" data-palette-script="{{ $script->id }}">
                        <span class="truncate">{{ $script->name }}</span>
                        <span class="ms-auto ps-3 text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $script->category->label() }}</span>
                    </flux:command.item>
                @endforeach
            </flux:command.items>
        </flux:command>
    </flux:modal>

    <x-script.run-modal :script="$runScript" :devices="$availableDevices" :groups="$runGroups" />
</div>
