<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\DTOs\MetricChart;
use App\Enums\DeviceTab;
use App\Enums\MetricRange;
use App\Models\Device;
use App\Queries\DeviceMetricQueries;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Overview extends Component
{
    public Device $device;

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device->load(['latestMetric.diskMetrics', 'latestInventory']);
    }

    #[On('echo-private:devices.{device.id},DeviceUpdated')]
    #[On('echo-private:devices.{device.id},SystemInventorySynced')]
    public function refreshDevice(): void
    {
        $this->device->refresh()->load(['latestMetric.diskMetrics', 'latestInventory']);
    }

    #[On('echo-private:devices.{device.id},CommandUpdated')]
    #[On('command-queued')]
    public function refreshCommands(): void {}

    /** @param array{deviceId?: int} $event */
    #[On('echo-private:devices,AlertChanged')]
    public function refreshAlerts(array $event): void
    {
        if (($event['deviceId'] ?? null) === $this->device->id) {
            return;
        }

        $this->skipRender();
    }

    public function render(DeviceMetricQueries $metrics): View
    {
        $trendRange = MetricRange::OneDay;
        $performance = $metrics->performance($this->device, $trendRange);

        return view('livewire.devices.overview', [
            'metric' => $this->device->latestMetric,
            'swapLabel' => $this->device->swapLabel(),
            'missingSwap' => $this->device->platform()->missingSwapLabel(),
            'disks' => $this->device->diskUsage(),
            'fullestDisk' => $this->device->fullestDisk(),
            'charts' => [MetricChart::cpu($performance), MetricChart::memory($performance)],
            'chartRange' => $trendRange->label(),
            'chartTimeFormat' => $trendRange->timeFormat(),
            'inventory' => $this->device->hasSystemInventory() ? $this->device->latestInventory?->snapshot() : null,
            'inventoryCollected' => $this->device->systemInventoryCollectedForHumans(),
            'recentCommands' => $this->device->commands()->with(['script', 'device'])->latest('queued_at')->limit(config('devices.metrics.recent_commands'))->get(),
            'openAlerts' => $this->device->unresolvedAlerts()->with('alertRule')->latest('triggered_at')->get(),
        ])->title(DeviceTab::Overview->pageTitle($this->device));
    }
}
