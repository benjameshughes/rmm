<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Details">
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
                        <flux:badge size="sm" :color="$apiKeyState->color()">{{ $apiKeyState->label() }}</flux:badge>
                        @if($apiKeyStateDetail)
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $apiKeyStateDetail }}</flux:text>
                        @endif
                    </dd>
                </div>
                @if($device->status->canResetEnrolment())
                    <flux:separator variant="subtle" />
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-zinc-500 dark:text-zinc-400">Enrolment</dt>
                        <dd>
                            <flux:button size="xs" variant="danger" icon="arrow-path" wire:click="resetEnrolment" wire:confirm="Reset enrolment for {{ $device->hostname }}? The current key stops working immediately. Run 'rmm reenroll' on the device, then approve it again.">
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

        <flux:card>
            <flux:heading size="sm" class="mb-4">System Information</flux:heading>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">Operating System</dt>
                    <dd class="font-medium">{{ $operatingSystem ?? '—' }}</dd>
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
                @if($totalRam)
                    <flux:separator variant="subtle" />
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500 dark:text-zinc-400">Total RAM</dt>
                        <dd class="font-medium">{{ $totalRam }}</dd>
                    </div>
                @endif
            </dl>
        </flux:card>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <flux:card>
            <flux:heading size="sm" class="mb-4">Group</flux:heading>
            <flux:select wire:model.live="selectedGroupId" placeholder="No group">
                <flux:select.option value="">No group</flux:select.option>
                @foreach($allGroups as $group)
                    <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:card>

        <flux:card>
            <flux:heading size="sm" class="mb-4">Tags</flux:heading>
            <flux:pillbox wire:model.live="selectedTagIds" multiple placeholder="Select tags...">
                @foreach($allTags as $tag)
                    <flux:pillbox.option value="{{ $tag->id }}">{{ $tag->name }}</flux:pillbox.option>
                @endforeach
            </flux:pillbox>
        </flux:card>
    </div>

    <x-device.network-adapters :adapters="$device->latestMetric?->networkMetrics" :cumulative="$cumulativeNetworkCounters" />
</x-device.shell>
