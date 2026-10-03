@props(['adapters' => null, 'cumulative' => false])

@if(filled($adapters))
    <flux:card {{ $attributes }}>
        <flux:heading size="sm" class="mb-4">Network Adapters</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Adapter</flux:table.column>
                <flux:table.column>Link Speed</flux:table.column>
                <flux:table.column>In</flux:table.column>
                <flux:table.column>Out</flux:table.column>
                <flux:table.column>{{ $cumulative ? 'Errors since boot' : 'Errors/s' }} (in / out)</flux:table.column>
                <flux:table.column>{{ $cumulative ? 'Drops since boot' : 'Drops/s' }} (in / out)</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach($adapters as $adapter)
                    <flux:table.row :key="'adapter-'.$adapter->id">
                        <flux:table.cell variant="strong">{{ $adapter->interface }}</flux:table.cell>
                        <flux:table.cell>{{ $adapter->linkSpeedForHumans() ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $adapter->receivedForHumans() ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $adapter->sentForHumans() ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$cumulative ? 'zinc' : $adapter->errorsColor()">{{ $adapter->errorsForHumans() ?? '—' }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$cumulative ? 'zinc' : $adapter->dropsColor()">{{ $adapter->dropsForHumans() ?? '—' }}</flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </flux:card>
@endif
