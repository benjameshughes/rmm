<div class="contents">
    <flux:modal wire:model="showModal" class="w-full md:max-w-lg">
        <form wire:submit="confirm" class="space-y-6" data-delete-path-modal>
            <div>
                <flux:heading size="lg">Delete from {{ $device->hostname }}</flux:heading>
                <flux:text class="mt-2">Removes one file or folder on the PC. Junctions and symlinks are removed as links, never followed. Windows, programs, profile folders and the RMM's own folders are always refused.</flux:text>
            </div>

            @if($isPathFixed)
                <div class="space-y-2">
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 dark:border-zinc-700 dark:bg-white/5">
                        <div class="break-all font-mono text-sm text-zinc-800 dark:text-white" data-delete-path>{{ $path }}</div>
                    </div>
                    <flux:error name="path" />
                </div>
            @else
                <flux:input wire:model.live.blur="path" label="Path" placeholder="C:\Veeam Backup Cache" class:input="font-mono" autocomplete="off" data-delete-path-input />
            @endif

            @if($target)
                <div class="space-y-2" data-delete-path-measured>
                    <dl class="grid grid-cols-3 gap-3">
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Size</dt>
                            <dd class="text-lg font-semibold tabular-nums text-zinc-800 dark:text-white">{{ $target->sizeForHumans() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Files</dt>
                            <dd class="text-lg font-semibold tabular-nums text-zinc-800 dark:text-white">{{ Number::format($target->files) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Modified</dt>
                            <dd class="text-sm text-zinc-800 dark:text-white">{{ $target->modifiedForHumans() ?? '—' }}</dd>
                        </div>
                    </dl>
                    <flux:text size="sm">{{ $target->scannedForHumans() }}</flux:text>
                </div>
            @elseif($path !== '')
                <flux:text size="sm" data-delete-path-unmeasured>No disk scan has measured this path, so its size is unknown until it is gone.</flux:text>
            @endif

            <flux:radio.group wire:model.live="mode" label="What happens to it" variant="cards" class="max-sm:flex-col">
                @foreach($modes as $deleteMode)
                    <flux:radio :value="$deleteMode->value" :label="$deleteMode->label()" :description="$deleteMode->description()" wire:key="delete-mode-{{ $deleteMode->value }}" />
                @endforeach
            </flux:radio.group>

            @if($requiresTyping)
                <flux:input wire:model="confirmation" label="Type {{ $leafName }} to confirm" autocomplete="off" data-delete-path-confirmation />
            @endif

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger" icon="trash" data-delete-path-confirm>{{ $confirmLabel }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
