<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Inventory\RefreshSystemInventory;
use App\Enums\DeviceTab;
use App\Models\Device;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class System extends Component
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

    #[On('echo-private:devices.{device.id},CommandUpdated')]
    #[On('command-queued')]
    public function refreshCommands(): void {}

    public function refreshInventory(RefreshSystemInventory $action): void
    {
        $this->authorize('runCommands', $this->device);

        $action($this->device, auth()->user());
        $this->dispatch('command-queued');

        Flux::toast(text: 'This tab updates by itself once the agent has run it.', heading: "System inventory queued for {$this->device->hostname}", variant: 'success');
    }

    public function render(): View
    {
        $hasSystemInventory = $this->device->hasSystemInventory();
        $inventory = $hasSystemInventory ? $this->device->latestInventory?->snapshot() : null;

        return view('livewire.devices.system', [
            'hasSystemInventory' => $hasSystemInventory,
            'inventory' => $inventory,
            'security' => $inventory?->security(),
            'users' => $inventory?->users(),
            'hardware' => $inventory?->hardware(),
            'software' => $inventory?->software(),
            'collected' => $this->device->systemInventoryCollectedForHumans(),
            'inventoryCommand' => $hasSystemInventory ? $this->device->inFlightCommands()->whereRelation('script', 'slug', config('inventory.system_slug'))->latest('id')->first() : null,
        ])->title(DeviceTab::System->pageTitle($this->device));
    }
}
