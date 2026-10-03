<?php

declare(strict_types=1);

namespace App\Livewire\Software;

use App\Models\Device;
use App\Queries\SoftwareQueries;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Software')]
final class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Device::class);
    }

    #[On('echo-private:devices,SoftwareInventorySynced')]
    public function refreshSoftware(): void {}

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(SoftwareQueries $software): View
    {
        $packages = $software->fleetPackages($this->search)->paginate(config('software.fleet_per_page'));

        return view('livewire.software.index', [
            'packages' => $packages,
            'versionSpread' => $software->versionSpread($packages->pluck('package_id')->all()),
            'inventoriedDevices' => Device::query()->whereNotNull('software_inventoried_at')->count(),
        ]);
    }
}
