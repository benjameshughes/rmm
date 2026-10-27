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
final class Security extends Component
{
    public Device $device;

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device->load('latestInventory');
    }

    #[On('echo-private:devices.{device.id},SystemInventorySynced')]
    public function refreshDevice(): void
    {
        $this->device->refresh()->load('latestInventory');
    }

    public function render(): View
    {
        $hasSystemInventory = $this->device->hasSystemInventory();
        $hasLinuxSecurityReport = $this->device->hasLinuxSecurityReport();

        return view('livewire.devices.security', [
            'hasSystemInventory' => $hasSystemInventory,
            'hasLinuxSecurityReport' => $hasLinuxSecurityReport,
            'security' => $hasSystemInventory ? $this->device->latestInventory?->snapshot()->security() : null,
            'linuxSecurity' => $hasLinuxSecurityReport ? $this->device->latestInventory?->snapshot()->linuxSecurity() : null,
        ])->title(DeviceTab::Security->pageTitle($this->device));
    }
}
