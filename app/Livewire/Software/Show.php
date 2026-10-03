<?php

declare(strict_types=1);

namespace App\Livewire\Software;

use App\Actions\Device\BulkExecuteScript;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Actions\Software\QueuePackageAction;
use App\Enums\PackageAction;
use App\Models\Device;
use App\Models\DeviceSoftware;
use App\Models\Script;
use App\Queries\SoftwareQueries;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One package across the fleet: who has which version, and upgrades for the ones behind.
 */
#[Layout('components.layouts.app')]
final class Show extends Component
{
    #[Locked]
    #[Url(as: 'id')]
    public string $packageId = '';

    private SoftwareQueries $software;

    public function boot(SoftwareQueries $software): void
    {
        $this->software = $software;
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Device::class);

        abort_unless($this->software->installsOf($this->packageId)->exists(), 404);
    }

    #[On('echo-private:devices,SoftwareInventorySynced')]
    public function refreshSoftware(): void
    {
        unset($this->installs);
    }

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

        $action(PackageAction::Upgrade, $install->device, $this->packageId, auth()->user());
        $this->dispatch('command-queued');

        Flux::toast(text: "{$install->name} on {$install->device->hostname}.", heading: PackageAction::Upgrade->queuedHeading(), variant: 'success');
    }

    public function upgradeAllOutdated(BulkExecuteScript $action, ValidateScriptParameterValues $validateParameters): void
    {
        $script = Script::findSystem(PackageAction::Upgrade->value);
        $parameters = $validateParameters($script, ['PackageId' => $this->packageId], 'packageId');
        $devices = $this->installs
            ->filter(fn (DeviceSoftware $install): bool => $install->isUpgradable)
            ->map(fn (DeviceSoftware $install): Device => $install->device)
            ->each(fn (Device $device) => $this->authorize('runCommands', $device));

        $queued = $action($script, $devices, auth()->user(), $parameters);
        $this->dispatch('command-queued');

        Flux::toast(
            text: $queued < $devices->count() ? 'Devices whose agent is older than '.config('agent.parameters_min_version').' were skipped. Update their agent first.' : 'Each device re-checks its software once the upgrade has run.',
            heading: "Upgrade queued on {$queued} of {$devices->count()} ".Str::plural('device', $devices->count()),
            variant: $queued < $devices->count() ? 'warning' : 'success',
        );
    }

    public function render(): View
    {
        $installs = $this->installs;

        return view('livewire.software.show', [
            'installs' => $installs,
            'package' => $installs->first(),
            'upgradableCount' => $installs->filter(fn (DeviceSoftware $install): bool => $install->isUpgradable)->count(),
        ])->title($installs->first()?->name ?? 'Software');
    }
}
