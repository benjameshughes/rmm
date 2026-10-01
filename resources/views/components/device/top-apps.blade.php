@props(['apps' => null])

@if(filled($apps))
    <flux:card {{ $attributes }}>
        <flux:heading size="sm" class="mb-4">Top Apps</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>App</flux:table.column>
                <flux:table.column align="end">CPU</flux:table.column>
                <flux:table.column align="end">RAM</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach($apps as $app)
                    <flux:table.row :key="'app-'.$app->id">
                        <flux:table.cell variant="strong">{{ $app->name }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $app->cpuForHumans() ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $app->memoryForHumans() ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </flux:card>
@endif
