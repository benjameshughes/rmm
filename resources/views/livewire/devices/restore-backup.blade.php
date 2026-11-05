<div>
    <flux:modal wire:model="showModal" class="w-full md:max-w-lg">
        <form wire:submit="restore" class="space-y-6" data-restore-form>
            <div>
                <flux:heading size="lg">Restore from backup</flux:heading>
                <flux:text class="mt-2">Restores {{ $device->hostname }}'s snapshot <span class="font-mono">{{ Str::limit($snapshotId, 8, '') }}</span> into a new folder or a staff account's profile. Nothing already there is overwritten.</flux:text>
            </div>

            <flux:select wire:model.live="targetDeviceId" label="Restore onto" variant="listbox" searchable>
                @foreach($targets as $target)
                    <flux:select.option value="{{ $target->id }}">{{ $target->hostname }}{{ $target->is($device) ? ' (this PC)' : '' }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:radio.group wire:model.live="mode" label="Restore" variant="cards" class="max-sm:flex-col">
                @foreach($modes as $restoreMode)
                    <flux:radio :value="$restoreMode->value" :label="$restoreMode->label()" :description="$restoreMode->description()" wire:key="restore-mode-{{ $restoreMode->value }}" />
                @endforeach
            </flux:radio.group>

            @if($isProfileMode)
                <flux:select wire:model.live="staffAccountId" label="Staff account" placeholder="Choose a staff account" variant="listbox" searchable data-restore-staff-account>
                    @foreach($staffAccounts as $account)
                        <flux:select.option value="{{ $account->id }}" wire:key="restore-account-{{ $account->id }}">{{ $account->username }} ({{ $account->full_name }})</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:autocomplete wire:model="sourceProfile" label="Profile folder in the backup" placeholder="C:\Users\SOPHIE.SMITH" description="Pick one from the snapshot or type it. Its Desktop, Documents and other folders go into the account's profile, which must already exist." class:input="font-mono" data-restore-source-profile>
                    @foreach($sourceProfiles as $profilePath)
                        <flux:autocomplete.item wire:key="restore-profile-{{ $loop->index }}">{{ $profilePath }}</flux:autocomplete.item>
                    @endforeach
                </flux:autocomplete>
            @else
                <flux:input wire:model="includePath" label="Only this file or folder" description="Optional. As it was on the PC, for example C:\Users\anna\Documents\Quotes." />

                <flux:input wire:model="target" label="Into folder" :placeholder="$defaultTarget" description="Optional. Files land under it by their full path." />
            @endif

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
