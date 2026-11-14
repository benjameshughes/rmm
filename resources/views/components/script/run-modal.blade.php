@props(['script', 'devices', 'groups'])

<flux:modal wire:model="showExecuteModal" class="w-full md:max-w-2xl">
    @if($script)
        <form wire:submit="executeOnDevices" class="space-y-6">
            <div>
                <flux:heading size="lg">Run {{ $script->name }}</flux:heading>
                <flux:text class="mt-2">Pick the devices to run it on. Only {{ $script->platform->label() }} devices that take commands are listed.</flux:text>
            </div>

            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                <x-script.row :script="$script" />
            </div>

            @if($devices->isEmpty())
                <flux:callout icon="computer-desktop" variant="secondary" heading="No devices can run this">
                    <flux:callout.text>No active {{ $script->platform->label() }} device takes commands right now.</flux:callout.text>
                </flux:callout>
            @else
                <div class="space-y-3">
                    <flux:pillbox wire:model="selectedDeviceIds" multiple searchable label="Devices" placeholder="Search devices..." data-run-devices>
                        @foreach($devices as $device)
                            <flux:pillbox.option value="{{ $device->id }}" wire:key="run-device-{{ $device->id }}">{{ $device->hostname }}</flux:pillbox.option>
                        @endforeach
                    </flux:pillbox>

                    @if($groups->isNotEmpty())
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:text size="sm">Add a group:</flux:text>
                            @foreach($groups as $group)
                                <flux:button size="xs" icon="plus" wire:click="addGroupToRun({{ $group->id }})" wire:key="run-group-{{ $group->id }}">{{ $group->name }}</flux:button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <x-script.parameter-inputs :parameters="$this->parameterFields" />

            <flux:error name="script" />

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showExecuteModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="play" :disabled="$devices->isEmpty()">Run</flux:button>
            </div>
        </form>
    @endif
</flux:modal>
