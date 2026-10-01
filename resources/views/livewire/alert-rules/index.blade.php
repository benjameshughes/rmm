<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Alert Rules</flux:heading>
        <div class="flex items-center gap-2">
            <flux:button as="a" :href="route('alerts.index')" wire:navigate variant="ghost">
                View Alerts
            </flux:button>
            <flux:button wire:click="$set('showModal', true)" variant="primary">
                New Rule
            </flux:button>
        </div>
    </div>
    <flux:separator variant="subtle" />

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Condition</flux:table.column>
                <flux:table.column>Duration</flux:table.column>
                <flux:table.column>Severity</flux:table.column>
                <flux:table.column>Alerts</flux:table.column>
                <flux:table.column>Active</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rules as $rule)
                    <flux:table.row wire:key="rule-{{ $rule->id }}">
                        <flux:table.cell>
                            <flux:text class="font-medium">{{ $rule->name }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text>{{ $rule->metric->label() }} {{ $rule->operator->label() }} {{ $rule->threshold }}{{ in_array($rule->metric->value, ['cpu', 'ram', 'disk']) ? '%' : ' min' }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $rule->duration_minutes }} min</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$rule->severity->color()">{{ $rule->severity->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="font-medium">{{ $rule->alerts_count }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:switch wire:click="toggleActive({{ $rule->id }})" :checked="$rule->is_active" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:button size="sm" variant="ghost" wire:click="edit({{ $rule->id }})" icon="pencil-square">
                                    Edit
                                </flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="delete({{ $rule->id }})" wire:confirm="Delete rule '{{ $rule->name }}'?" icon="trash">
                                    Delete
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">No alert rules configured. Create one to start monitoring.</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showModal" class="md:w-[28rem]" @cancel="resetForm">
        <div class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Rule' : 'New Alert Rule' }}</flux:heading>

            <flux:input wire:model="name" label="Name" placeholder="e.g. High CPU" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="metric" label="Metric" required>
                    <flux:select.option value="">Select...</flux:select.option>
                    @foreach($metrics as $m)
                        <flux:select.option value="{{ $m->value }}">{{ $m->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="operator" label="Operator" required>
                    @foreach($operators as $op)
                        <flux:select.option value="{{ $op->value }}">{{ $op->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="threshold" label="Threshold" type="number" step="0.1" min="0" required />
                <flux:input wire:model="duration_minutes" label="Duration (minutes)" type="number" min="1" max="1440" required />
            </div>

            <flux:select wire:model="severity" label="Severity" required>
                @foreach($severities as $s)
                    <flux:select.option value="{{ $s->value }}">{{ $s->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="resetForm" variant="ghost">Cancel</flux:button>
                @if($editingId)
                    <flux:button wire:click="update" variant="primary">Update</flux:button>
                @else
                    <flux:button wire:click="create" variant="primary">Create</flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</div>
