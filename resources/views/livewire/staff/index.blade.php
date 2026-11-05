<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Staff</flux:heading>
        <div class="flex items-center gap-2">
            @can('lock-staff-screens')
                <flux:button variant="ghost" icon="lock-closed" x-on:click="$dispatch('confirm-action', { heading: 'Set screen lock', message: {{ Js::from('Lock every staff PC after '.$screenLockMinutes.' idle minutes? It takes effect after a restart.') }}, confirm: 'Queue', action: () => $wire.lockScreens() })">
                    Screen Lock
                </flux:button>
            @endcan
            @can('create', App\Models\StaffAccount::class)
                <flux:button wire:click="create" variant="primary">New Account</flux:button>
            @endcan
        </div>
    </div>
    <flux:separator variant="subtle" />

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Account</flux:table.column>
                <flux:table.column>Targets</flux:table.column>
                <flux:table.column>Latest sync</flux:table.column>
                <flux:table.column>Last logon</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->accounts as $account)
                    <flux:table.row wire:key="staff-{{ $account->id }}">
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:text class="font-medium font-mono">{{ $account->username }}</flux:text>
                                @if ($account->is_admin)
                                    <flux:badge size="sm" color="amber">Admin</flux:badge>
                                @endif
                                @unless ($account->is_enabled)
                                    <flux:badge size="sm" color="red">Disabled</flux:badge>
                                @endunless
                            </div>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $account->full_name }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($account->deviceGroups as $group)
                                    <flux:badge size="sm" color="zinc" icon="rectangle-group">{{ $group->name }}</flux:badge>
                                @endforeach
                                @foreach ($account->tags as $tag)
                                    <flux:badge size="sm" :color="$tag->color ?? 'zinc'">{{ $tag->name }}</flux:badge>
                                @endforeach
                                @foreach ($account->devices as $device)
                                    <flux:badge size="sm" color="zinc" icon="computer-desktop">{{ $device->hostname }}</flux:badge>
                                @endforeach
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @forelse ($this->latestSyncs->get($account->id, collect()) as $command)
                                    <flux:tooltip :content="$command->summaryLine()" wire:key="sync-{{ $command->id }}">
                                        <flux:badge size="sm" :color="$command->status->color()">{{ $command->device?->hostname }}: {{ $command->status->label() }}</flux:badge>
                                    </flux:tooltip>
                                @empty
                                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Not synced yet</flux:text>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($lastLogon = $this->lastLogons->get(Str::lower($account->username)))
                                <flux:text size="sm" data-last-logon-device="{{ $lastLogon->device->hostname }}">{{ $lastLogon->last_logon_at->diffForHumans() }} on {{ $lastLogon->device->hostname }}</flux:text>
                            @else
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400" data-last-logon-never>Never seen</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-1">
                                @can('update', $account)
                                    <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="sync({{ $account->id }})">Sync</flux:button>
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $account->id }})">Edit</flux:button>
                                    @can('reveal-staff-passwords')
                                        <flux:button size="sm" variant="ghost" icon="key" x-on:click="$dispatch('confirm-action', { heading: 'Reset password', message: {{ Js::from('Generate a new password for '.$account->username.' and push it to its PCs?') }}, confirm: 'Reset', action: () => $wire.resetAccountPassword({{ $account->id }}) })">Reset</flux:button>
                                    @endcan
                                    @if ($account->is_enabled)
                                        <flux:button size="sm" variant="ghost" icon="no-symbol" x-on:click="$dispatch('confirm-action', { heading: 'Disable account', message: {{ Js::from('Disable '.$account->username.' on every PC it targets?') }}, confirm: 'Disable', danger: true, action: () => $wire.disable({{ $account->id }}) })">Disable</flux:button>
                                    @endif
                                @endcan
                                @can('delete', $account)
                                    <flux:button size="sm" variant="ghost" icon="trash" x-on:click="$dispatch('confirm-action', { heading: 'Delete account', message: {{ Js::from('Remove '.$account->username.' from every PC it targets and delete it?') }}, confirm: 'Delete', danger: true, action: () => $wire.delete({{ $account->id }}) })">Delete</flux:button>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">No staff accounts yet. Create one to put a local user on the office PCs.</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showModal" class="md:w-[32rem]" @cancel="resetForm">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Staff Account' : 'New Staff Account' }}</flux:heading>

            <flux:input wire:model="username" label="Username" placeholder="e.g. sophie" class="font-mono" :disabled="$editingId !== null" :description="$editingId ? 'Fixed once created: the PCs know the account by it.' : null" required />

            <flux:input wire:model="fullName" label="Full name" placeholder="e.g. Sophie Smith" required />

            <div class="space-y-2">
                <flux:input wire:model="password" type="password" label="Password" viewable copyable :placeholder="$editingId ? 'Leave blank to keep the current password' : null" :required="$editingId === null" />
                <div class="flex gap-2">
                    <flux:button size="sm" variant="ghost" icon="sparkles" wire:click="generatePassword">Generate</flux:button>
                    @if ($editingId)
                        @can('reveal-staff-passwords')
                            <flux:button size="sm" variant="ghost" icon="eye" wire:click="revealPassword">Show current</flux:button>
                        @endcan
                    @endif
                </div>
            </div>

            <div class="flex gap-6">
                <flux:switch wire:model="isAdmin" label="Administrator" />
                <flux:switch wire:model="isEnabled" label="Enabled" />
            </div>

            <flux:pillbox wire:model="deviceGroupIds" multiple searchable label="Groups" placeholder="Select groups...">
                @foreach ($groups as $group)
                    <flux:pillbox.option value="{{ $group->id }}">{{ $group->name }}</flux:pillbox.option>
                @endforeach
            </flux:pillbox>

            <flux:pillbox wire:model="tagIds" multiple searchable label="Tags" placeholder="Select tags...">
                @foreach ($tags as $tag)
                    <flux:pillbox.option value="{{ $tag->id }}">{{ $tag->name }}</flux:pillbox.option>
                @endforeach
            </flux:pillbox>

            <flux:pillbox wire:model="deviceIds" multiple searchable label="PCs" placeholder="Select PCs...">
                @foreach ($devices as $device)
                    <flux:pillbox.option value="{{ $device->id }}">{{ $device->hostname }}</flux:pillbox.option>
                @endforeach
            </flux:pillbox>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="resetForm" variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary">{{ $editingId ? 'Save and sync' : 'Create and sync' }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showResetPassword" @close="closeResetPassword" class="md:w-96">
        <div class="space-y-6">
            <flux:heading size="lg">New password</flux:heading>
            <flux:text>Queued to every PC the account targets. Hand it over now; it can be shown again from Edit.</flux:text>
            <flux:input :value="$resetPassword" readonly copyable class="font-mono" />
            <div class="flex justify-end">
                <flux:button wire:click="closeResetPassword" variant="primary">Done</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
