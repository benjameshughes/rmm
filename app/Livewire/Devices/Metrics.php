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
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Metrics extends Component
{
    public Device $device;

    #[Url]
    public string $range = MetricRange::OneDay->value;

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device->load('latestMetric');
    }

    /**
     * A hand-edited query string falls back to the default range rather than erroring.
     */
    #[Computed]
    public function metricRange(): MetricRange
    {
        return MetricRange::tryFrom($this->range) ?? MetricRange::OneDay;
    }

    public function render(DeviceMetricQueries $metrics): View
    {
        $performance = $metrics->performance($this->device, $this->metricRange);

        return view('livewire.devices.metrics', [
            'charts' => [
                MetricChart::cpuAndMemory($performance),
                MetricChart::processorLoad($performance),
                MetricChart::diskAndPageFile($performance),
                MetricChart::network($metrics->network($this->device, $this->metricRange)),
            ],
            'ranges' => MetricRange::cases(),
            'timeFormat' => $this->metricRange->timeFormat(),
        ])->title(DeviceTab::Metrics->pageTitle($this->device));
    }
}
