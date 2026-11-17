@props(['columns' => 4, 'rows' => 8])

<flux:card {{ $attributes->class('p-0! sm:p-0!') }}>
    <flux:skeleton.group animate="shimmer" class="space-y-4 p-4">
        <div class="flex gap-6">
            @foreach (range(1, $columns) as $column)
                <flux:skeleton.line class="w-24" wire:key="column-skeleton-{{ $column }}" />
            @endforeach
        </div>

        @foreach (range(1, $rows) as $row)
            <flux:skeleton.line size="lg" wire:key="row-skeleton-{{ $row }}" />
        @endforeach
    </flux:skeleton.group>
</flux:card>
