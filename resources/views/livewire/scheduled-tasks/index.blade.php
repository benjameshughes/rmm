<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Scheduled Tasks</flux:heading>
        <flux:button wire:click="$set('showModal', true)" variant="primary">
            New Schedule
        </flux:button>
    </div>
    <flux:separator variant="subtle" />

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Action</flux:table.column>
                <flux:table.column>Target</flux:table.column>
                <flux:table.column>Schedule</flux:table.column>
                <flux:table.column>Last Run</flux:table.column>
                <flux:table.column>Next Run</flux:table.column>
                <flux:table.column>Active</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($tasks as $task)
                    <flux:table.row wire:key="task-{{ $task->id }}">
                        <flux:table.cell>
                            <flux:text class="font-medium">{{ $task->name }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:badge size="sm" color="zinc">{{ $task->action->label() }}</flux:badge>
                                @if($task->script)
                                    <flux:button as="a" size="sm" variant="ghost" :href="route('scripts.show', $task->script)" wire:navigate>
                                        {{ $task->script->name }}
                                    </flux:button>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" color="zinc">{{ $task->target_type->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="font-mono text-sm text-zinc-500 dark:text-zinc-400">{{ $task->cron_expression }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $task->last_run_at?->diffForHumans() ?? 'Never' }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $task->next_run_at?->diffForHumans() ?? '—' }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:switch wire:click="toggleActive({{ $task->id }})" :checked="$task->is_active" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:button size="sm" variant="ghost" wire:click="runNow({{ $task->id }})" icon="play">
                                    Run
                                </flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="edit({{ $task->id }})" icon="pencil-square">
                                    Edit
                                </flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="delete({{ $task->id }})" wire:confirm="Delete schedule '{{ $task->name }}'?" icon="trash">
                                    Delete
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8">
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">No scheduled tasks. Create one to run scripts or wake devices on a schedule.</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showModal" class="md:w-[28rem]" @cancel="resetForm">
        <div class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Schedule' : 'New Schedule' }}</flux:heading>

            <flux:input wire:model="name" label="Name" placeholder="e.g. Daily System Info" required />

            <flux:select wire:model.live="action" label="Action" required>
                @foreach($actions as $scheduledAction)
                    <flux:select.option value="{{ $scheduledAction->value }}">{{ $scheduledAction->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if($this->requiresScript)
                <flux:select wire:model.live="script_id" label="Script" placeholder="Select a script..." variant="listbox" searchable required>
                    @foreach($scripts as $script)
                        <flux:select.option value="{{ $script->id }}">{{ $script->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <x-script.parameter-inputs :parameters="$this->parameterFields" />
            @endif

            <div>
                <flux:input wire:model="cron_expression" label="Cron Expression" placeholder="0 2 * * *" class="font-mono" required />
                <div class="flex flex-wrap gap-2 mt-2">
                    <flux:button size="sm" variant="ghost" wire:click="$set('cron_expression', '0 * * * *')">Hourly</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="$set('cron_expression', '0 2 * * *')">Daily 2am</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="$set('cron_expression', '0 */6 * * *')">Every 6h</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="$set('cron_expression', '0 3 * * 0')">Weekly</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="$set('cron_expression', '0 4 1 * *')">Monthly</flux:button>
                </div>
            </div>

            <flux:select wire:model.live="target_type" label="Target" required>
                @foreach($targetTypes as $type)
                    <flux:select.option value="{{ $type->value }}">{{ $type->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if($target_type === 'group')
                <flux:select wire:model="target_id" label="Group" placeholder="Select a group..." required>
                    @foreach($groups as $group)
                        <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @elseif($target_type === 'tag')
                <flux:select wire:model="target_id" label="Tag" placeholder="Select a tag..." required>
                    @foreach($tags as $tag)
                        <flux:select.option value="{{ $tag->id }}">{{ $tag->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @elseif($target_type === 'device')
                <flux:select wire:model="target_id" label="Device" placeholder="Select a device..." variant="listbox" searchable required>
                    @foreach($devices as $device)
                        <flux:select.option value="{{ $device->id }}">{{ $device->hostname }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

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
