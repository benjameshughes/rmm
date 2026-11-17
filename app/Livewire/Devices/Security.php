<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\DTOs\Inventory\InventorySecurity;
use App\DTOs\Inventory\LinuxSecurity;
use App\Enums\DeviceTab;
use App\Models\Device;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Lazy]
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
        $this->renderIsland(name: 'security');
        $this->skipRender();
    }

    public function placeholder(): View
    {
        return view('livewire.devices.placeholders.security');
    }

    public function render(): View
    {
        return view('livewire.devices.security')->title(DeviceTab::Security->pageTitle($this->device));
    }

    #[Computed]
    public function hasSystemInventory(): bool
    {
        return $this->device->hasSystemInventory();
    }

    #[Computed]
    public function hasLinuxSecurityReport(): bool
    {
        return $this->device->hasLinuxSecurityReport();
    }

    #[Computed]
    public function security(): ?InventorySecurity
    {
        return $this->hasSystemInventory ? $this->device->latestInventory?->snapshot()->security() : null;
    }

    #[Computed]
    public function linuxSecurity(): ?LinuxSecurity
    {
        return $this->hasLinuxSecurityReport ? $this->device->latestInventory?->snapshot()->linuxSecurity() : null;
    }
}
