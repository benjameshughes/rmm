<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Enums\DeviceTab;
use App\Models\Device;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Apps extends Component
{
    public Device $device;

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device->load('latestMetric.appMetrics');
    }

    #[On('echo-private:devices.{device.id},DeviceUpdated')]
    public function refreshDevice(): void
    {
        $this->device->refresh()->load('latestMetric.appMetrics');
    }

    public function render(): View
    {
        return view('livewire.devices.apps', [
            'apps' => $this->device->latestMetric?->appMetrics,
        ])->title(DeviceTab::Apps->pageTitle($this->device));
    }
}
