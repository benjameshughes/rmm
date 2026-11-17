<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\DTOs\MetricChart;
use App\Enums\DeviceTab;
use App\Enums\MetricRange;
use App\Models\Device;
use App\Queries\DeviceMetricQueries;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Lazy]
final class Metrics extends Component
{
    public Device $device;

    #[Url]
    public string $range = MetricRange::OneDay->value;

    private DeviceMetricQueries $metrics;

    public function boot(DeviceMetricQueries $metrics): void
    {
        $this->metrics = $metrics;
    }

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
    }

    /**
     * A hand-edited query string falls back to the default range rather than erroring.
     */
    #[Computed]
    public function metricRange(): MetricRange
    {
        return MetricRange::tryFrom($this->range) ?? MetricRange::OneDay;
    }

    /**
     * @return array<int, MetricChart>
     */
    #[Computed]
    public function charts(): array
    {
        $performance = $this->metrics->performance($this->device, $this->metricRange);

        return [
            MetricChart::cpuAndMemory($performance),
            MetricChart::processorLoad($performance),
            MetricChart::diskAndPageFile($performance, $this->device->swapLabel()),
            MetricChart::diskThroughput($this->metrics->diskThroughput($this->device, $this->metricRange)),
            MetricChart::network($this->metrics->network($this->device, $this->metricRange)),
        ];
    }

    public function placeholder(): View
    {
        return view('livewire.devices.placeholders.metrics');
    }

    public function render(): View
    {
        return view('livewire.devices.metrics')->title(DeviceTab::Metrics->pageTitle($this->device));
    }
}
