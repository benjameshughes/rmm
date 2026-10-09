@props(['items', 'canChange' => false])

<x-dashboard.section title="Quarantine" description="Moved aside instead of deleted. It still takes up space on the drive until it is purged." {{ $attributes }} data-quarantine>
    <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
        @foreach($items as $item)
            <div class="flex flex-wrap items-center justify-between gap-3 py-2.5" wire:key="quarantine-{{ $item['quarantine']->id }}" data-quarantine-item="{{ $item['quarantine']->path }}">
                <div class="min-w-0 space-y-0.5">
                    <div class="truncate font-mono text-sm text-zinc-800 dark:text-white" title="{{ $item['quarantine']->quarantined_to }}">{{ $item['quarantine']->path }}</div>
                    <flux:text size="sm" class="tabular-nums">{{ $item['quarantine']->summaryForHumans() }}</flux:text>
                </div>

                @if($item['command'])
                    <x-device.commands.busy :command="$item['command']" variant="ghost" size="xs" />
                @elseif($canChange)
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="Actions for {{ $item['quarantine']->path }}" data-quarantine-actions />

                        <flux:menu>
                            <flux:menu.item wire:click="restoreQuarantine({{ $item['quarantine']->id }})" icon="arrow-uturn-left" data-restore-quarantine>Restore</flux:menu.item>
                            <flux:menu.item x-on:click="$dispatch('confirm-action', { heading: 'Purge from quarantine', message: {{ Js::from($item['quarantine']->purgeConfirmation()) }}, confirm: 'Purge now', danger: true, action: () => $wire.purgeQuarantine({{ $item['quarantine']->id }}) })" icon="trash" variant="danger" data-purge-quarantine>Purge now</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        @endforeach
    </div>
</x-dashboard.section>
