<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0 space-y-2">
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="xl" level="1" class="truncate">{{ $device->hostname }}</flux:heading>
            <x-device.status-badge :label="$statusLabel" :color="$statusColor" size="lg" />
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
            <span>{{ $device->lastSeenForHumans() }}</span>
            @if($device->last_ip)
                <span class="font-mono">{{ $device->last_ip }}</span>
            @endif
            @if($operatingSystem)
                <span>{{ $operatingSystem }}</span>
            @endif
            @if($device->agent_version)
                <span>Agent {{ $device->agent_version }}</span>
            @endif
            <x-device.agent-update-badge :device="$device" :latest-version="$latestAgentVersion" />
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @if($device->isWakeable)
            <flux:button wire:click="wake" icon="sun">Wake</flux:button>
        @endif

        <flux:button wire:click="$set('showCommandModal', true)" icon="command-line" variant="primary">Run Command</flux:button>
        <flux:button wire:click="$set('showScriptModal', true)" icon="code-bracket">Run Script</flux:button>

        <flux:dropdown position="bottom" align="end">
            <flux:button icon="power" icon:trailing="chevron-down">Power</flux:button>

            <flux:menu>
                <flux:menu.item wire:click="restart" wire:confirm="Are you sure you want to restart {{ $device->hostname }}?" icon="arrow-path">Restart</flux:menu.item>
                <flux:menu.item wire:click="powerOff" wire:confirm="Are you sure you want to power off {{ $device->hostname }}?" icon="power" variant="danger">Power Off</flux:menu.item>
                <flux:menu.item wire:click="logOff" wire:confirm="Log the signed-in user off {{ $device->hostname }}? Unsaved work is lost." icon="arrow-right-start-on-rectangle">Log Off</flux:menu.item>

                <flux:menu.separator />

                <flux:menu.item wire:click="checkForUpdates" icon="arrow-down-tray">Check for Updates</flux:menu.item>
                <flux:menu.item wire:click="updateAgent" icon="arrow-up-circle">Update Agent</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>

    <flux:modal wire:model="showScriptModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Run Script</flux:heading>
                <flux:text class="mt-2">Execute a script on {{ $device->hostname }}.</flux:text>
            </div>

            <flux:select wire:model.live="selectedScriptId" label="Script" placeholder="Select a script..." variant="listbox" searchable>
                @foreach($scripts as $script)
                    <flux:select.option value="{{ $script->id }}">{{ $script->name }} ({{ $script->platform->name }})</flux:select.option>
                @endforeach
            </flux:select>

            <x-script.parameter-inputs :parameters="$this->parameterFields" />

            <flux:error name="script" />

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showScriptModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="runScript" variant="primary" icon="play">Execute</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showCommandModal" class="w-full md:max-w-2xl">
        <form wire:submit="runAdHocCommand" class="space-y-6">
            <div>
                <flux:heading size="lg">Run Command</flux:heading>
                <flux:text class="mt-2">Run a one-off command on {{ $device->hostname }}. Open it from the Commands tab to read its output.</flux:text>
            </div>

            <flux:textarea wire:model="commandText" label="Command" rows="6" class="font-mono" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="commandType" label="Shell">
                    @foreach($this->commandTypes as $type)
                        <flux:select.option value="{{ $type->value }}">{{ $type->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="commandTimeoutSeconds" type="number" label="Timeout (seconds)" :min="config('commands.ad_hoc.timeout_seconds.min')" :max="config('commands.ad_hoc.timeout_seconds.max')" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showCommandModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="play">Run</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
