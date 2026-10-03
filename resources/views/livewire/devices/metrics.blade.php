<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Metrics">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="lg">{{ $this->metricRange->label() }}</flux:heading>

        <flux:radio.group wire:model.live="range" variant="segmented" size="sm">
            @foreach($ranges as $option)
                <flux:radio :value="$option->value" :label="$option->value" wire:key="range-{{ $option->value }}" />
            @endforeach
        </flux:radio.group>
    </div>

    <x-device.stats.performance :metric="$device->latestMetric" />

    <div class="grid gap-6 xl:grid-cols-2">
        @foreach($charts as $chart)
            <flux:card wire:key="chart-{{ $loop->index }}-{{ $range }}">
                <x-device.metrics.chart :chart="$chart" :time-format="$timeFormat" />
            </flux:card>
        @endforeach
    </div>
</x-device.shell>
