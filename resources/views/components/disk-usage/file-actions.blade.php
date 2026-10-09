@props(['path', 'deleting' => null, 'canDelete' => false])

@if($deleting)
    <x-device.commands.busy :command="$deleting" variant="ghost" size="xs" label="Deleting..." />
@elseif($canDelete)
    <flux:dropdown position="bottom" align="end">
        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="Actions for {{ $path }}" data-file-actions />

        <flux:menu>
            <flux:menu.item wire:click="$dispatch('delete-path', { path: {{ Js::from($path) }}, kind: 'file' })" icon="trash" variant="danger" data-delete-file>Delete...</flux:menu.item>
        </flux:menu>
    </flux:dropdown>
@endif
