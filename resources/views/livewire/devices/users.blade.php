<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Users">
    @if (! $isWindows)
        <flux:callout icon="information-circle" data-local-accounts-windows-only>
            <flux:callout.text>Local accounts are reported by Windows PCs only. Linux servers are managed through the farm tools.</flux:callout.text>
        </flux:callout>
    @elseif ($rows->isEmpty() && ! $isSupported)
        <flux:callout icon="arrow-down-tray" color="amber" data-local-accounts-unsupported>
            <flux:callout.text>This PC's agent ({{ $device->agent_version ?? 'unknown version' }}) does not report local accounts. Update it to {{ $minAgentVersion }} or later.</flux:callout.text>
        </flux:callout>
    @elseif ($rows->isEmpty())
        <flux:callout icon="clock" data-local-accounts-pending>
            <flux:callout.text>No accounts reported yet. The agent sends them with its metrics every few minutes.</flux:callout.text>
        </flux:callout>
    @else
        <flux:card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Account</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Last logon</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($rows as $row)
                        <flux:table.row wire:key="local-account-{{ $row->account->id }}" data-local-account="{{ $row->account->username }}">
                            <flux:table.cell>
                                <flux:text class="font-medium font-mono">{{ $row->account->username }}</flux:text>
                                @if ($row->account->full_name)
                                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $row->account->full_name }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-1">
                                    @if ($row->isManaged)
                                        <flux:badge size="sm" color="green" icon="check-badge" data-badge="managed">Managed</flux:badge>
                                    @elseif ($row->account->isBuiltIn)
                                        <flux:badge size="sm" color="zinc" icon="cog-6-tooth" data-badge="built-in">Built-in</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="amber" icon="question-mark-circle" data-badge="unmanaged">Unmanaged</flux:badge>
                                    @endif
                                    @if ($row->account->is_admin)
                                        <flux:badge size="sm" color="orange" data-badge="admin">Admin</flux:badge>
                                    @endif
                                    @unless ($row->account->is_enabled)
                                        <flux:badge size="sm" color="red" data-badge="disabled">Disabled</flux:badge>
                                    @endunless
                                    @if ($row->account->is_signed_in)
                                        <flux:badge size="sm" color="blue" icon="user" data-badge="signed-in">Signed in</flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text size="sm">{{ $row->lastLogonForHumans() }}</flux:text>
                                @if ($row->lastLogonAgo())
                                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $row->lastLogonAgo() }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @can('runCommands', $device)
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($row->canAdopt())
                                            @can('create', App\Models\StaffAccount::class)
                                                <flux:button size="sm" variant="ghost" icon="user-plus" wire:click="startAdopt({{ $row->account->id }})" data-adopt="{{ $row->account->id }}">Adopt</flux:button>
                                            @endcan
                                        @endif
                                        @if ($row->canDisable())
                                            <flux:button size="sm" variant="ghost" icon="no-symbol" data-disable="{{ $row->account->id }}" x-on:click="$dispatch('confirm-action', { heading: 'Disable account', message: {{ Js::from('Disable '.$row->account->username.' on '.$device->hostname.'? The account and its files stay.') }}, confirm: 'Disable', danger: true, action: () => $wire.disable({{ $row->account->id }}) })">Disable</flux:button>
                                        @endif
                                        @if ($row->canDelete())
                                            <flux:button size="sm" variant="ghost" icon="trash" data-delete="{{ $row->account->id }}" x-on:click="$dispatch('confirm-action', { heading: 'Delete account', message: {{ Js::from('Delete '.$row->account->username.' from '.$device->hostname.'? Its profile folder is left on disk.') }}, confirm: 'Delete', danger: true, action: () => $wire.delete({{ $row->account->id }}) })">Delete</flux:button>
                                        @endif
                                    </div>
                                @endcan
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    <flux:modal wire:model="showAdopt" class="md:w-96" @cancel="cancelAdopt">
        <form wire:submit="adopt" class="space-y-6">
            <div class="space-y-1">
                <flux:heading size="lg">Adopt {{ $adopting?->username }}</flux:heading>
                <flux:text size="sm">It becomes a staff account targeting {{ $device->hostname }}. The current password cannot be read, so this one replaces it.</flux:text>
            </div>

            <flux:error name="username" />

            <div class="space-y-2">
                <flux:input wire:model="password" type="password" label="New password" viewable copyable required />
                <flux:button size="sm" variant="ghost" icon="sparkles" wire:click="generatePassword">Generate</flux:button>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="cancelAdopt">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Adopt</flux:button>
            </div>
        </form>
    </flux:modal>
</x-device.shell>
