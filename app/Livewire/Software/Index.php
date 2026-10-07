<?php

declare(strict_types=1);

namespace App\Livewire\Software;

use App\DTOs\Software\PackageCommandPlan;
use App\DTOs\Software\SoftwareFilters;
use App\Enums\PackageAction;
use App\Enums\SoftwareCoverage;
use App\Enums\SoftwareSort;
use App\Enums\SoftwareSource;
use App\Livewire\Concerns\QueuesSelectedPackageCommands;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Tag;
use App\Queries\SoftwareQueries;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every package across the fleet, filtered and sorted like the devices table,
 * with ticked packages upgraded or uninstalled in bulk. A group or tag filter
 * also limits the devices those bulk actions reach.
 */
#[Layout('components.layouts.app')]
#[Title('Software')]
final class Index extends Component
{
    use QueuesSelectedPackageCommands;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $source = '';

    #[Url(as: 'updates')]
    public bool $isOutdatedOnly = false;

    #[Url]
    public string $coverage = '';

    #[Url(as: 'group')]
    public string $groupFilter = '';

    #[Url(as: 'tag')]
    public string $tagFilter = '';

    #[Url(as: 'sort')]
    public string $sortBy = SoftwareSort::Behind->value;

    #[Url(as: 'direction')]
    public string $sortDirection = 'desc';

    /** @var array<int, string> */
    public array $selectedPackages = [];

    public bool $selectAll = false;

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
    #[On('echo-private:devices,CommandUpdated')]
    #[On('command-queued')]
    public function refreshSoftware(): void {}

    /**
     * Hand-edited query string values fall back to no filter rather than erroring.
     */
    #[Computed]
    public function filters(): SoftwareFilters
    {
        return new SoftwareFilters(
            search: $this->search,
            source: SoftwareSource::tryFrom($this->source),
            isOutdatedOnly: $this->isOutdatedOnly,
            coverage: SoftwareCoverage::tryFrom($this->coverage),
            groupId: ctype_digit($this->groupFilter) ? (int) $this->groupFilter : null,
            tagId: ctype_digit($this->tagFilter) ? (int) $this->tagFilter : null,
        );
    }

    #[Computed]
    public function listSort(): SoftwareSort
    {
        return SoftwareSort::tryFrom($this->sortBy) ?? SoftwareSort::Behind;
    }

    #[Computed]
    public function listSortDirection(): string
    {
        return in_array($this->sortDirection, ['asc', 'desc'], true) ? $this->sortDirection : $this->listSort->defaultDirection();
    }

    /**
     * Clicking the sorted column flips it; a new column starts in its natural direction.
     */
    public function sort(string $column): void
    {
        $sort = SoftwareSort::tryFrom($column) ?? SoftwareSort::Behind;

        $this->sortDirection = $this->listSort === $sort
            ? ($this->listSortDirection === 'asc' ? 'desc' : 'asc')
            : $sort->defaultDirection();
        $this->sortBy = $sort->value;

        unset($this->listSort, $this->listSortDirection);
        $this->resetPage();
    }

    /**
     * Any filter change starts again from page one with nothing ticked, so a
     * bulk action never reaches a package the list no longer shows.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'source', 'isOutdatedOnly', 'coverage', 'groupFilter', 'tagFilter'], true)) {
            unset($this->filters);
            $this->resetPage();
            $this->clearSelection();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'source', 'isOutdatedOnly', 'coverage', 'groupFilter', 'tagFilter');
        unset($this->filters);
        $this->resetPage();
        $this->clearSelection();
    }

    /**
     * Ticks every package on the current page.
     */
    public function updatedSelectAll(): void
    {
        $this->selectedPackages = $this->selectAll ? $this->packages->pluck('package_id')->all() : [];
    }

    public function clearSelection(): void
    {
        $this->selectedPackages = [];
        $this->selectAll = false;
    }

    #[Computed]
    public function packages(): LengthAwarePaginator
    {
        return $this->software->fleetPackages($this->filters, $this->listSort, $this->listSortDirection)->paginate(config('software.fleet_per_page'));
    }

    protected function planSelectedPackages(PackageAction $action): PackageCommandPlan
    {
        return PackageCommandPlan::forInstalls($action, $this->software->installsOfPackages($this->selectedPackages, $this->filters)->get());
    }

    public function render(): View
    {
        $packageIds = $this->packages->pluck('package_id')->all();

        return view('livewire.software.index', [
            'packages' => $this->packages,
            'versionSpread' => $this->software->versionSpread($packageIds, $this->filters),
            'packageCommands' => $this->software->packageCommandsByPackage($packageIds),
            'inventoriedDevices' => $this->software->inventoriedDeviceCount($this->filters),
            'groups' => DeviceGroup::query()->orderBy('name')->get(),
            'tags' => Tag::query()->orderBy('name')->get(),
        ]);
    }
}
