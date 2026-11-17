@props(['headings' => [], 'rows' => 6])

<flux:card {{ $attributes }}>
    <flux:table>
        <flux:table.columns>
            @foreach ($headings as $heading)
                <flux:table.column wire:key="heading-skeleton-{{ $loop->index }}">{{ $heading }}</flux:table.column>
            @endforeach
        </flux:table.columns>
        <flux:table.rows>
            @foreach (range(1, $rows) as $row)
                <flux:table.row wire:key="row-skeleton-{{ $row }}">
                    @foreach ($headings as $heading)
                        <flux:table.cell wire:key="cell-skeleton-{{ $row }}-{{ $loop->index }}">
                            <flux:skeleton.line animate="shimmer" />
                        </flux:table.cell>
                    @endforeach
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</flux:card>
