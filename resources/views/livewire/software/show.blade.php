<div class="space-y-6">
    <div class="space-y-4">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('software.index')" wire:navigate>Software</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $package->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <flux:heading size="xl" level="1" class="truncate">{{ $package->name }}</flux:heading>
                <flux:text class="mt-1 font-mono">{{ $package->package_id }}</flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:checkbox wire:model="closeAppFirst" label="Close the app first" class="me-2" data-close-app-first />
                @if($upgradableCount > 0)
                    <flux:button variant="primary" icon="arrow-up-circle" x-on:click="$dispatch('confirm-action', { heading: 'Upgrade everywhere', message: {{ Js::from('Upgrade '.$package->name.' on '.$upgradableCount.' '.Str::plural('device', $upgradableCount).'?') }}, confirm: 'Upgrade', action: () => $wire.upgradeAllOutdated() })">
                        Upgrade on all outdated ({{ $upgradableCount }})
                    </flux:button>
                @endif
                @if($package->source !== null && $missingCount > 0)
                    <flux:button icon="arrow-down-tray" x-on:click="$dispatch('confirm-action', { heading: 'Install everywhere', message: {{ Js::from('Install '.$package->name.' on the '.$missingCount.' '.Str::plural('device', $missingCount).' that do not have it?') }}, confirm: 'Install', action: () => $wire.installEverywhere() })" data-install-everywhere>
                        Install where missing ({{ $missingCount }})
                    </flux:button>
                @endif
                <flux:button variant="danger" icon="trash" x-on:click="$dispatch('confirm-action', { heading: 'Uninstall everywhere', message: {{ Js::from('Uninstall '.$package->name.' from all '.$installs->count().' '.Str::plural('device', $installs->count()).'?') }}, confirm: 'Uninstall', danger: true, action: () => $wire.uninstallEverywhere() })" data-uninstall-everywhere>
                    Uninstall everywhere ({{ $installs->count() }})
                </flux:button>
            </div>
        </div>
    </div>

    <flux:error name="script" />
    <flux:error name="packageId.PackageId" />

    <flux:card class="p-0! sm:p-0!">
        <flux:table class="px-4">
            <flux:table.columns>
                <flux:table.column>Device</flux:table.column>
                <flux:table.column>Version</flux:table.column>
                <flux:table.column class="hidden md:table-cell">Checked</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach($installs as $install)
                    <flux:table.row :key="'install-'.$install->id">
                        <flux:table.cell>
                            <x-device.list.identity :device="$install->device" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-col items-start gap-1">
                                <flux:text size="sm" class="font-mono">{{ $install->installed_version ?? '—' }}</flux:text>
                                <x-software.update-badge :package="$install" />
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            <flux:text size="sm">{{ $install->last_seen_at->diffForHumans() }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($packageCommands->has($install->device_id))
                                <div class="flex justify-end">
                                    <x-device.commands.busy :command="$packageCommands->get($install->device_id)" />
                                </div>
                            @elseif($install->isUpgradable)
                                @can('runCommands', $install->device)
                                    <div class="flex justify-end">
                                        <flux:button size="sm" icon="arrow-up-circle" wire:click="upgrade({{ $install->id }})">Upgrade</flux:button>
                                    </div>
                                @endcan
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
