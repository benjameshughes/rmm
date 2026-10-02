<div class="space-y-6">
    <div>
        <flux:heading size="xl">Edit Script</flux:heading>
        <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">Update {{ $script->name }}.</flux:text>
    </div>
    <flux:separator variant="subtle" />

    <form wire:submit="save" class="space-y-6 max-w-2xl">
        <flux:input wire:model="name" label="Name" placeholder="e.g. Clear Temp Files" required />

        <flux:textarea wire:model="description" label="Description" placeholder="What does this script do?" rows="2" />

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:select wire:model="category" label="Category" placeholder="Select..." required>
                @foreach($categories as $category)
                    <flux:select.option value="{{ $category->value }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="platform" label="Platform" placeholder="Select..." required>
                @foreach($platforms as $platform)
                    <flux:select.option value="{{ $platform->value }}">{{ $platform->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="script_type" label="Script Type" placeholder="Select..." required>
                @foreach($scriptTypes as $type)
                    <flux:select.option value="{{ $type->value }}">{{ $type->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:textarea wire:model="script_content" label="Script Content" placeholder="Enter script content..." rows="8" class="font-mono text-sm" required />

        <x-script.parameter-editor :rows="$parameterRows" :types="$parameterTypes" />

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="timeout_seconds" label="Timeout (seconds)" type="number" min="10" max="7200" />

            <div class="flex items-end pb-2">
                <flux:switch wire:model="requires_admin" label="Requires admin privileges" />
            </div>
        </div>

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">Update Script</flux:button>
            <flux:button as="a" variant="ghost" :href="route('scripts.show', $script)" wire:navigate>Cancel</flux:button>
        </div>
    </form>
</div>
