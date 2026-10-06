<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Software\QueuePackageAction;
use App\Actions\Software\RefreshSoftwareInventory;
use App\Enums\DeviceTab;
use App\Enums\PackageAction;
use App\Models\Device;
use App\Queries\SoftwareQueries;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
final class Apps extends Component
{
    use WithPagination;

    public Device $device;

    #[Url(as: 'q')]
    public string $softwareSearch = '';

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device->load('latestMetric.appMetrics');
    }

    #[On('echo-private:devices.{device.id},DeviceUpdated')]
    #[On('echo-private:devices.{device.id},SoftwareInventorySynced')]
    public function refreshDevice(): void
    {
        $this->device->refresh()->load('latestMetric.appMetrics');
    }

    #[On('echo-private:devices.{device.id},CommandUpdated')]
    #[On('command-queued')]
    public function refreshCommands(): void {}

    public function updatingSoftwareSearch(): void
    {
        $this->resetPage();
    }

    public function refreshInventory(RefreshSoftwareInventory $action): void
    {
        $this->authorize('runCommands', $this->device);

        $action($this->device, auth()->user());
        $this->dispatch('command-queued');

        Flux::toast(text: 'The list updates by itself once the agent has run it.', heading: "Inventory queued for {$this->device->hostname}", variant: 'success');
    }

    public function upgradePackage(int $softwareId, QueuePackageAction $action): void
    {
        $this->runPackageAction(PackageAction::Upgrade, $softwareId, $action);
    }

    public function uninstallPackage(int $softwareId, QueuePackageAction $action): void
    {
        $this->runPackageAction(PackageAction::Uninstall, $softwareId, $action);
    }

    /**
     * Only packages in this device's own inventory can be changed from its page.
     */
    private function runPackageAction(PackageAction $packageAction, int $softwareId, QueuePackageAction $action): void
    {
        $this->authorize('runCommands', $this->device);

        $package = $this->device->software()->findOrFail($softwareId);
        abort_if($packageAction === PackageAction::Upgrade && ! $package->isUpgradable, 422);
        $action($packageAction, $this->device, $package->package_id, auth()->user());
        $this->dispatch('command-queued');

        Flux::toast(text: "{$package->name} on {$this->device->hostname}. The list refreshes once it has run.", heading: $packageAction->queuedHeading(), variant: 'success');
    }

    public function render(SoftwareQueries $software): View
    {
        $hasSoftwareInventory = $this->device->hasSoftwareInventory();

        return view('livewire.devices.apps', [
            'apps' => $this->device->latestMetric?->appMetrics,
            'hasSoftwareInventory' => $hasSoftwareInventory,
            'lastChecked' => $this->device->software_inventoried_at === null ? null : 'Last checked '.$this->device->software_inventoried_at->diffForHumans().' by winget.',
            'software' => $hasSoftwareInventory ? $software->forDevice($this->device, $this->softwareSearch)->paginate(config('software.device_per_page')) : null,
            'inventoryCommand' => $hasSoftwareInventory ? $this->device->inFlightCommands()->whereRelation('script', 'slug', config('software.inventory_slug'))->latest('id')->first() : null,
        ])->title(DeviceTab::Apps->pageTitle($this->device));
    }
}
