<div>
    <flux:modal wire:model="showModal" class="w-full md:max-w-lg">
        <form wire:submit="migrate" class="space-y-6" data-migrate-form>
            <div>
                <flux:heading size="lg">Migrate user</flux:heading>
                <flux:text class="mt-2">Moves a user's documents and settings into a staff account on {{ $device->hostname }} with Microsoft's User State Migration Tool. Both accounts must be signed out.</flux:text>
            </div>

            <flux:radio.group wire:model.live="mode" label="Migrate" variant="cards" class="max-sm:flex-col">
                @foreach($modes as $migrationMode)
                    <flux:radio :value="$migrationMode->value" :label="$migrationMode->label()" :description="$migrationMode->description()" wire:key="migrate-mode-{{ $migrationMode->value }}" />
                @endforeach
            </flux:radio.group>

            @if($isFromAnotherPc)
                <flux:select wire:model="sourceDeviceId" label="Old PC" placeholder="Choose the PC to migrate from" variant="listbox" searchable description="Only PCs with backups enabled: the capture travels through its backup." data-migrate-source>
                    @foreach($sources as $source)
                        <flux:select.option value="{{ $source->id }}" wire:key="migrate-source-{{ $source->id }}">{{ $source->hostname }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input wire:model="fromUser" label="User to migrate" placeholder="OLDDOMAIN\michelle" description="DOMAIN\name, or a local username on that PC." class:input="font-mono" data-migrate-from-user />

            <flux:select wire:model="staffAccountId" label="Into staff account" placeholder="Choose a staff account" variant="listbox" searchable description="It must already exist on this PC." data-migrate-staff-account>
                @foreach($staffAccounts as $account)
                    <flux:select.option value="{{ $account->id }}" wire:key="migrate-account-{{ $account->id }}">{{ $account->username }} ({{ $account->full_name }})</flux:select.option>
                @endforeach
            </flux:select>

            <flux:error name="script" />
            <flux:error name="migrate" />

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="arrows-right-left">Migrate</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
