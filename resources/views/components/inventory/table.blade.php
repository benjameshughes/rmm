@props(['table', 'name', 'empty' => 'Nothing reported.'])

@if($table->isEmpty())
    <flux:text size="sm">{{ $empty }}</flux:text>
@else
    <flux:table {{ $attributes }}>
        <flux:table.columns>
            @foreach($table->columns as $column)
                <flux:table.column>{{ $column }}</flux:table.column>
            @endforeach
        </flux:table.columns>
        <flux:table.rows>
            @foreach($table->rows as $index => $row)
                <flux:table.row :key="$name.'-'.$index">
                    @foreach($row as $cell)
                        <flux:table.cell class="whitespace-normal break-words">{{ $cell ?? '—' }}</flux:table.cell>
                    @endforeach
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
@endif
