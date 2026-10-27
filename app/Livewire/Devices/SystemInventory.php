<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Inventory\RefreshSystemInventory;
use App\Models\Device;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * When the system inventory last ran, with Run now. Dropped onto every tab that shows inventory data.
 */
final class SystemInventory extends Component
{
    public Device $device;

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);
    }

    #[On('echo-private:devices.{device.id},SystemInventorySynced')]
    public function refreshDevice(): void
    {
        $this->device->refresh();
    }

    #[On('echo-private:devices.{device.id},CommandUpdated')]
    #[On('echo-private:devices.{device.id},CommandProgressed')]
    public function refreshCommands(): void {}

    public function refreshInventory(RefreshSystemInventory $action): void
    {
        $this->authorize('runCommands', $this->device);

        $action($this->device, auth()->user());
        $this->dispatch('command-queued');

        Flux::toast(text: 'The page updates by itself once the agent has run it.', heading: "System inventory queued for {$this->device->hostname}", variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.devices.system-inventory', [
            'collected' => $this->device->systemInventoryCollectedForHumans() ?? 'Not collected yet.',
            'inventoryCommand' => $this->device->inFlightCommands()->whereRelation('script', 'slug', config('inventory.system_slug'))->latest('id')->first(),
        ]);
    }
}
