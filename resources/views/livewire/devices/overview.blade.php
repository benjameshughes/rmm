<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Overview">
    <x-device.commands.in-flight :in-flight="$inFlight" />

    <x-device.stats.summary :metric="$metric" :disk="$fullestDisk" />

    @if($openAlerts->isNotEmpty())
        <flux:callout icon="bell-alert" color="red">
            <flux:callout.heading>{{ $openAlerts->count() }} open {{ Str::plural('alert', $openAlerts->count()) }}</flux:callout.heading>
            <flux:callout.text>
                <ul class="mt-1 space-y-1">
                    @foreach($openAlerts as $alert)
                        <li class="flex flex-wrap items-center gap-2" wire:key="alert-{{ $alert->id }}">
                            <flux:badge size="sm" :color="$alert->severity->color()">{{ $alert->severity->label() }}</flux:badge>
                            <span>{{ $alert->conditionLabel() }}</span>
                            <span class="font-mono">{{ $alert->valueLabel() }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ $alert->triggered_at->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" :href="route('alerts.index')" wire:navigate>View alerts</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <x-device.stats.performance :metric="$metric" :swap-label="$swapLabel" :missing-swap="$missingSwap" />

    <x-device.linux-health :metric="$metric" />

    <flux:card>
        <x-device.metrics.chart :chart="$trend" :time-format="$trendTimeFormat" title="CPU & RAM, last 24 hours" />
    </flux:card>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card class="space-y-4">
            <div class="flex items-center justify-between gap-4">
                <flux:heading size="sm">Recent Commands</flux:heading>
                <flux:button size="sm" variant="ghost" icon:trailing="arrow-right" :href="route('devices.commands', $device)" wire:navigate>All commands</flux:button>
            </div>

            @forelse($recentCommands as $command)
                <div class="flex cursor-pointer items-center justify-between gap-4 rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-white/5" wire:key="command-{{ $command->id }}" wire:click="$dispatch('show-command', { commandId: {{ $command->id }} })">
                    <div class="min-w-0">
                        <flux:text class="truncate font-medium text-zinc-800 dark:text-white">{{ $command->displayName() }}</flux:text>
                        <flux:text size="sm">{{ $command->queued_at->diffForHumans() }}</flux:text>
                    </div>
                    <x-device.commands.status :command="$command" />
                </div>
            @empty
                <flux:text>No commands run on this device yet.</flux:text>
            @endforelse
        </flux:card>

        <div class="space-y-6">
            <x-device.disk-storage :disks="$disks" />
            <x-device.netdata-alerts :metric="$metric" />
        </div>
    </div>

    <livewire:commands.detail />
</x-device.shell>
