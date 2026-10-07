<?php

declare(strict_types=1);

namespace App\Livewire\Software;

use App\Actions\Software\QueuePackageAction;
use App\Actions\Software\QueuePackageCommands;
use App\DTOs\Software\PackageCommandPlan;
use App\Enums\PackageAction;
use App\Models\Device;
use App\Models\DeviceSoftware;
use App\Queries\SoftwareQueries;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One package across the fleet: who has which version, upgrades for the ones behind,
 * removing it everywhere, and installing it on more devices through the install picker.
 */
#[Layout('components.layouts.app')]
final class Show extends Component
{
    #[Locked]
    #[Url(as: 'id')]
    public string $packageId = '';

    public bool $closeAppFirst = false;

    private SoftwareQueries $software;

    public function boot(SoftwareQueries $software): void
    {
        $this->software = $software;
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Device::class);
    }

    #[On('echo-private:devices,SoftwareInventorySynced')]
    public function refreshSoftware(): void
    {
        unset($this->installs);
    }

    #[On('echo-private:devices,CommandUpdated')]
    #[On('command-queued')]
    public function refreshCommands(): void {}

    /** @return Collection<int, DeviceSoftware> */
    #[Computed]
    public function installs(): Collection
    {
        return $this->software->installsOf($this->packageId)->get();
    }

    public function upgrade(int $softwareId, QueuePackageAction $action): void
    {
        $install = $this->installs->firstWhere('id', $softwareId);
        abort_unless($install?->isUpgradable, 404);
        $this->authorize('runCommands', $install->device);

        $action(PackageAction::Upgrade, $install->device, $this->packageId, auth()->user(), closeAppFirst: $this->closeAppFirst);
        $this->dispatch('command-queued');

        Flux::toast(text: "{$install->name} on {$install->device->hostname}.", heading: PackageAction::Upgrade->queuedHeading(), variant: 'success');
    }

    public function upgradeAllOutdated(QueuePackageCommands $queue): void
    {
        $devices = $this->installs
            ->filter(fn (DeviceSoftware $install): bool => $install->isUpgradable)
            ->map(fn (DeviceSoftware $install): Device => $install->device);

        $this->queueOnDevices(PackageAction::Upgrade, $devices, $queue);
    }

    public function uninstallEverywhere(QueuePackageCommands $queue): void
    {
        $this->queueOnDevices(PackageAction::Uninstall, $this->installs->map(fn (DeviceSoftware $install): Device => $install->device), $queue);
    }

    /**
     * Devices whose agent is too old for a parameterised script are skipped,
     * so the toast says how many it was queued on.
     *
     * @param  BaseCollection<int, Device>  $devices
     */
    private function queueOnDevices(PackageAction $packageAction, BaseCollection $devices, QueuePackageCommands $queue): void
    {
        $devices->each(fn (Device $device) => $this->authorize('runCommands', $device));

        $queued = $queue(new PackageCommandPlan($packageAction, collect([$this->packageId => $devices->values()])), auth()->user(), closeAppFirst: $this->closeAppFirst);
        $this->dispatch('command-queued');

        Flux::toast(
            text: $queued < $devices->count() ? 'Devices whose agent is older than '.config('agent.parameters_min_version').' were skipped. Update their agent first.' : 'Each device re-checks its software once it has run.',
            heading: "{$packageAction->queuedHeading()} on {$queued} of {$devices->count()} ".Str::plural('device', $devices->count()),
            variant: $queued < $devices->count() ? 'warning' : 'success',
        );
    }

    public function render(): View
    {
        $installs = $this->installs;

        if ($installs->isEmpty()) {
            return view('livewire.software.removed')->title('Software');
        }

        return view('livewire.software.show', [
            'installs' => $installs,
            'package' => $installs->first(),
            'upgradableCount' => $installs->filter(fn (DeviceSoftware $install): bool => $install->isUpgradable)->count(),
            'packageCommands' => $this->software->packageCommandsFor($this->packageId),
        ])->title($installs->first()?->name ?? 'Software');
    }
}
