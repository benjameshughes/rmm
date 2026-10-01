<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Alerts</flux:heading>
        <flux:button as="a" :href="route('alert-rules.index')" wire:navigate variant="ghost">
            Manage Rules
        </flux:button>
    </div>
    <flux:separator variant="subtle" />

    <div class="flex flex-wrap items-center gap-4">
        <flux:select wire:model.live="statusFilter" placeholder="All Statuses" class="max-w-48">
            <flux:select.option value="">All Statuses</flux:select.option>
            @foreach($statuses as $status)
                <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="severityFilter" placeholder="All Severities" class="max-w-48">
            <flux:select.option value="">All Severities</flux:select.option>
            @foreach($severities as $severity)
                <flux:select.option value="{{ $severity->value }}">{{ $severity->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:card>
        <flux:table :paginate="$alerts">
            <flux:table.columns>
                <flux:table.column>Device</flux:table.column>
                <flux:table.column>Condition</flux:table.column>
                <flux:table.column>Value</flux:table.column>
                <flux:table.column>Severity</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Triggered</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($alerts as $alert)
                    <flux:table.row wire:key="alert-{{ $alert->id }}">
                        <flux:table.cell>
                            <flux:button as="a" size="sm" variant="ghost" :href="route('devices.show', $alert->device)" wire:navigate>
                                {{ $alert->device->hostname }}
                            </flux:button>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text>{{ $alert->conditionLabel() }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="font-medium font-mono">{{ $alert->valueLabel() }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$alert->severity->color()">{{ $alert->severity->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$alert->status->color()">{{ $alert->status->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $alert->triggered_at->diffForHumans() }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                @if($alert->status === \App\Enums\AlertStatus::Triggered)
                                    <flux:button size="sm" variant="ghost" wire:click="acknowledge({{ $alert->id }})">
                                        Acknowledge
                                    </flux:button>
                                @endif
                                @if($alert->status !== \App\Enums\AlertStatus::Resolved)
                                    <flux:button size="sm" variant="ghost" wire:click="resolve({{ $alert->id }})">
                                        Resolve
                                    </flux:button>
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">No alerts. Everything looks good.</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
