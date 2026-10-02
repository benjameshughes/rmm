<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Audit Log</flux:heading>
    </div>
    <flux:separator variant="subtle" />

    <div class="flex flex-wrap items-center gap-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search device, script, user or IP..." class="max-w-72" />
        <flux:select wire:model.live="userFilter" placeholder="All Users" class="max-w-48">
            <flux:select.option value="">All Users</flux:select.option>
            <flux:select.option value="system">System</flux:select.option>
            @foreach($users as $user)
                <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="actionFilter" placeholder="All Actions" class="max-w-56">
            <flux:select.option value="">All Actions</flux:select.option>
            @foreach($actions as $action)
                <flux:select.option value="{{ $action->value }}">{{ $action->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:card>
        <flux:table :paginate="$auditLogs">
            <flux:table.columns>
                <flux:table.column>When</flux:table.column>
                <flux:table.column>User</flux:table.column>
                <flux:table.column>Action</flux:table.column>
                <flux:table.column>Subject</flux:table.column>
                <flux:table.column>Details</flux:table.column>
                <flux:table.column>IP</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($auditLogs as $auditLog)
                    <flux:table.row wire:key="audit-log-{{ $auditLog->id }}">
                        <flux:table.cell>
                            <flux:text class="text-zinc-500 dark:text-zinc-400" title="{{ $auditLog->created_at }}">{{ $auditLog->created_at->diffForHumans() }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text>{{ $auditLog->actorName() }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$auditLog->action->color()">{{ $auditLog->action->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="font-medium">{{ $auditLog->subjectLabel() }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell class="max-w-md whitespace-normal">
                            <flux:text class="font-mono text-xs break-all">{{ $auditLog->detailsSummary() }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="font-mono text-xs text-zinc-500 dark:text-zinc-400" title="{{ $auditLog->user_agent }}">{{ $auditLog->ip ?? '—' }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">Nothing in the audit log yet.</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
