<div>
    <flux:modal wire:model="showModal" class="w-full md:max-w-lg">
        <form wire:submit="restore" class="space-y-6" data-restore-form>
            <div>
                <flux:heading size="lg">Restore from backup</flux:heading>
                <flux:text class="mt-2">Restores {{ $device->hostname }}'s snapshot <span class="font-mono">{{ Str::limit($snapshotId, 8, '') }}</span> into a new folder. Nothing already there is overwritten.</flux:text>
            </div>

            <flux:select wire:model="targetDeviceId" label="Restore onto" variant="listbox" searchable>
                @foreach($targets as $target)
                    <flux:select.option value="{{ $target->id }}">{{ $target->hostname }}{{ $target->is($device) ? ' (this PC)' : '' }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="includePath" label="Only this file or folder" description="Optional. As it was on the PC, for example C:\Users\anna\Documents\Quotes." />

            <flux:input wire:model="target" label="Into folder" :placeholder="$defaultTarget" description="Optional. Files land under it by their full path." />

            <flux:error name="snapshotId" />
            <flux:error name="script" />
            <flux:error name="restore" />

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="arrow-uturn-left">Restore</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
