<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Software\QueuePackageAction;
use App\Actions\Software\RefreshSoftwareInventory;
use App\DTOs\Software\PackageCommandPlan;
use App\DTOs\Software\SoftwareFilters;
use App\Enums\DeviceTab;
use App\Enums\PackageAction;
use App\Enums\SoftwareSource;
use App\Livewire\Concerns\QueuesSelectedPackageCommands;
use App\Models\Device;
use App\Models\DeviceSoftware;
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
    use QueuesSelectedPackageCommands;
    use WithPagination;

    public Device $device;

    #[Url(as: 'q')]
    public string $softwareSearch = '';

    #[Url]
    public string $source = '';

    #[Url(as: 'updates')]
    public bool $isOutdatedOnly = false;

    /** @var array<int, string> DeviceSoftware IDs */
    public array $selectedSoftware = [];

    public bool $selectAll = false;

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
    #[On('echo-private:devices.{device.id},CommandProgressed')]
    #[On('command-queued')]
    public function refreshCommands(): void {}

    /**
     * A filter change starts again from page one with nothing ticked.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['softwareSearch', 'source', 'isOutdatedOnly'], true)) {
            $this->resetPage();
            $this->clearSelection();
        }
    }

    /**
     * Ticks every app on the current page.
     */
    public function updatedSelectAll(SoftwareQueries $software): void
    {
        $this->selectedSoftware = $this->selectAll
            ? $software->forDevice($this->device, $this->filters())->paginate(config('software.device_per_page'))->pluck('id')->map(fn (int $id): string => (string) $id)->all()
            : [];
    }

    public function clearSelection(): void
    {
        $this->selectedSoftware = [];
        $this->selectAll = false;
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
        $action($packageAction, $this->device, $package->package_id, auth()->user(), closeAppFirst: $this->closeAppFirst);
        $this->dispatch('command-queued');

        Flux::toast(text: "{$package->name} on {$this->device->hostname}. The list refreshes once it has run.", heading: $packageAction->queuedHeading(), variant: 'success');
    }

    /**
     * Only packages in this device's own inventory can be ticked.
     */
    protected function planSelectedPackages(PackageAction $action): PackageCommandPlan
    {
        $installs = $this->device->software()->whereIn('id', $this->selectedSoftware)->get()
            ->each(fn (DeviceSoftware $install): DeviceSoftware => $install->setRelation('device', $this->device));

        return PackageCommandPlan::forInstalls($action, $installs);
    }

    private function filters(): SoftwareFilters
    {
        return new SoftwareFilters(
            search: $this->softwareSearch,
            source: SoftwareSource::tryFrom($this->source),
            isOutdatedOnly: $this->isOutdatedOnly,
        );
    }

    public function render(SoftwareQueries $software): View
    {
        $hasSoftwareInventory = $this->device->hasSoftwareInventory();

        return view('livewire.devices.apps', [
            'apps' => $this->device->latestMetric?->appMetrics,
            'hasSoftwareInventory' => $hasSoftwareInventory,
            'lastChecked' => $this->device->software_inventoried_at === null ? null : 'Last checked '.$this->device->software_inventoried_at->diffForHumans().' by winget.',
            'software' => $hasSoftwareInventory ? $software->forDevice($this->device, $this->filters())->paginate(config('software.device_per_page')) : null,
            'packageCommands' => $hasSoftwareInventory ? $software->packageCommandsOnDevice($this->device) : collect(),
            'inventoryCommand' => $hasSoftwareInventory ? $this->device->inFlightCommands()->whereRelation('script', 'slug', config('software.inventory_slug'))->latest('id')->first() : null,
        ])->title(DeviceTab::Apps->pageTitle($this->device));
    }
}
