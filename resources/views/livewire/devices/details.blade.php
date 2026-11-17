<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Details">
    @island(name: 'details', always: true)
        @if($this->hasSystemInventory)
            <livewire:devices.system-inventory :device="$device" :key="'system-inventory-'.$device->id" />
        @endif

        <div class="grid gap-6 md:grid-cols-2">
            <flux:card>
                <flux:heading size="sm" class="mb-4">Device Details</flux:heading>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500 dark:text-zinc-400">Last Seen</dt>
                        <dd class="font-medium">{{ $device->last_seen?->diffForHumans() ?? '—' }}</dd>
                    </div>
                    <flux:separator variant="subtle" />
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500 dark:text-zinc-400">IP Address</dt>
                        <dd class="font-mono font-medium">{{ $device->last_ip ?? '—' }}</dd>
                    </div>
                    <flux:separator variant="subtle" />
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-zinc-500 dark:text-zinc-400">API Key</dt>
                        <dd class="flex items-center gap-2 font-medium">
                            <flux:badge size="sm" :color="$this->apiKeyState->color()">{{ $this->apiKeyState->label() }}</flux:badge>
                            @if($this->apiKeyStateDetail)
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $this->apiKeyStateDetail }}</flux:text>
                            @endif
                        </dd>
                    </div>
                    @if($device->status->canResetEnrolment())
                        <flux:separator variant="subtle" />
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">Enrolment</dt>
                            <dd>
                                <flux:button size="xs" variant="danger" icon="arrow-path" x-on:click="$dispatch('confirm-action', { heading: 'Reset enrolment', message: {{ Js::from('Reset enrolment for '.$device->hostname.'? The current key stops working immediately. Run \'rmm reenroll\' on the device, then approve it again.') }}, confirm: 'Reset', danger: true, action: () => $wire.resetEnrolment() })">
                                    Reset enrolment
                                </flux:button>
                            </dd>
                        </div>
                    @endif
                    @if($device->agent_version)
                        <flux:separator variant="subtle" />
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">Agent Version</dt>
                            <dd class="font-medium">{{ $device->agent_version }}</dd>
                        </div>
                    @endif
                    @if(filled($device->mac_addresses))
                        <flux:separator variant="subtle" />
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">MAC Addresses</dt>
                            <dd class="space-y-1 text-right font-mono font-medium">
                                @foreach($device->mac_addresses as $macAddress)
                                    <div wire:key="mac-{{ $macAddress }}">{{ $macAddress }}</div>
                                @endforeach
                            </dd>
                        </div>
                    @endif
                </dl>
            </flux:card>

            @if($this->inventory)
                <flux:card>
                    <flux:heading size="sm" class="mb-4">This PC</flux:heading>
                    <x-inventory.facts :facts="$this->inventory->overview()" />
                </flux:card>
            @else
                <flux:card>
                    <flux:heading size="sm" class="mb-4">System Information</flux:heading>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">Operating System</dt>
                            <dd class="font-medium">{{ $this->operatingSystem ?? '—' }}</dd>
                        </div>
                        @if($device->os_version)
                            <flux:separator variant="subtle" />
                            <div class="flex justify-between gap-4">
                                <dt class="text-zinc-500 dark:text-zinc-400">OS Version</dt>
                                <dd class="font-medium">{{ $device->os_version }}</dd>
                            </div>
                        @endif
                        @if($device->cpu_model)
                            <flux:separator variant="subtle" />
                            <div class="flex justify-between gap-4">
                                <dt class="shrink-0 text-zinc-500 dark:text-zinc-400">CPU</dt>
                                <dd class="text-right font-medium">{{ $device->cpu_model }}</dd>
                            </div>
                        @endif
                        @if($device->cpu_cores)
                            <flux:separator variant="subtle" />
                            <div class="flex justify-between gap-4">
                                <dt class="text-zinc-500 dark:text-zinc-400">CPU Cores</dt>
                                <dd class="font-medium">{{ $device->cpu_cores }}</dd>
                            </div>
                        @endif
                        @if($this->totalRam)
                            <flux:separator variant="subtle" />
                            <div class="flex justify-between gap-4">
                                <dt class="text-zinc-500 dark:text-zinc-400">Total RAM</dt>
                                <dd class="font-medium">{{ $this->totalRam }}</dd>
                            </div>
                        @endif
                    </dl>
                </flux:card>
            @endif
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <flux:card>
                <flux:heading size="sm" class="mb-4">Group</flux:heading>
                <flux:select wire:model.live="selectedGroupId" placeholder="No group">
                    <flux:select.option value="">No group</flux:select.option>
                    @foreach($this->allGroups as $group)
                        <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:card>

            <flux:card>
                <flux:heading size="sm" class="mb-4">Tags</flux:heading>
                <flux:pillbox wire:model.live="selectedTagIds" multiple placeholder="Select tags...">
                    @foreach($this->allTags as $tag)
                        <flux:pillbox.option value="{{ $tag->id }}">{{ $tag->name }}</flux:pillbox.option>
                    @endforeach
                </flux:pillbox>
            </flux:card>
        </div>

        @if($this->inventory)
            <x-inventory.hardware :hardware="$this->inventory->hardware()" />
            <x-inventory.users :users="$this->inventory->users()" />
        @endif

        <x-device.network-adapters :adapters="$device->latestMetric?->networkMetrics" :cumulative="$this->cumulativeNetworkCounters" />
    @endisland
</x-device.shell>
