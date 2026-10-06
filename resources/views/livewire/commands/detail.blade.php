<div>
    <flux:modal wire:model="showModal" class="w-full md:max-w-3xl">
        @if($command = $this->command)
            <div class="space-y-6">
                <div class="flex flex-wrap items-center gap-3">
                    <flux:heading size="lg">{{ $command->displayName() }}</flux:heading>
                    <flux:badge size="sm" :color="$command->status->color()">{{ $command->status->label() }}</flux:badge>
                    <flux:badge size="sm" color="zinc">{{ $command->script_type }}</flux:badge>
                    @can('cancel', $command)
                        <flux:button size="sm" variant="ghost" icon="x-circle" x-on:click="$dispatch('confirm-action', { heading: 'Cancel command', message: 'Cancel this command before it runs?', confirm: 'Cancel command', danger: true, action: () => $wire.cancelCommand({{ $command->id }}) })">Cancel</flux:button>
                    @endcan
                </div>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm md:grid-cols-3">
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Device</dt>
                        <dd class="font-medium">{{ $command->device->hostname }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Exit code</dt>
                        <dd class="font-mono font-medium">{{ $command->exit_code ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Run by</dt>
                        <dd class="font-medium">{{ $command->queuedBy?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Queued</dt>
                        <dd class="font-medium">{{ $command->queued_at?->inDisplayTimezone()->format('d M H:i:s') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Finished</dt>
                        <dd class="font-medium">{{ $command->completed_at?->inDisplayTimezone()->format('d M H:i:s') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Took</dt>
                        <dd class="font-medium">{{ $command->durationForHumans() ?? '—' }}</dd>
                    </div>
                </dl>

                @if($command->error_message)
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="$command->error_message" />
                @endif

                <div class="space-y-2">
                    <flux:heading size="sm">Output</flux:heading>
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-zinc-950 p-4 font-mono text-xs text-zinc-100">{{ $command->stdout() ?: 'No output.' }}</pre>
                </div>

                @if($stderr = $command->stderr())
                    <div class="space-y-2">
                        <flux:heading size="sm">Errors</flux:heading>
                        <pre class="max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-red-950 p-4 font-mono text-xs text-red-100">{{ $stderr }}</pre>
                    </div>
                @endif

                <details class="text-sm">
                    <summary class="cursor-pointer text-zinc-500 dark:text-zinc-400">Script that ran</summary>
                    <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-zinc-100 p-4 font-mono text-xs dark:bg-zinc-800">{{ $command->script_content }}</pre>
                </details>
            </div>
        @else
            <flux:text>That command no longer exists.</flux:text>
        @endif
    </flux:modal>
</div>
