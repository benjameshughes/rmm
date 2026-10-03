@props(['sort', 'current', 'direction'])

<flux:table.column sortable :sorted="$sort === $current" :direction="$direction" wire:click="sort('{{ $sort->value }}')" {{ $attributes }}>{{ $sort->label() }}</flux:table.column>
