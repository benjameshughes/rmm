<?php

declare(strict_types=1);

namespace App\Livewire\Hardware;

use App\DTOs\Inventory\HardwareRow;
use App\Enums\HardwareSort;
use App\Models\Device;
use App\Queries\HardwareQueries;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Hardware')]
final class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sort')]
    public string $sortBy = HardwareSort::Hostname->value;

    #[Url(as: 'direction')]
    public string $sortDirection = 'asc';

    public function mount(): void
    {
        $this->authorize('viewAny', Device::class);
    }

    #[On('echo-private:devices,SystemInventorySynced')]
    public function refreshHardware(): void {}

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * A hand-edited sort falls back to PC, A to Z.
     */
    #[Computed]
    public function listSort(): HardwareSort
    {
        return HardwareSort::tryFrom($this->sortBy) ?? HardwareSort::Hostname;
    }

    #[Computed]
    public function listSortDirection(): string
    {
        return in_array($this->sortDirection, ['asc', 'desc'], true) ? $this->sortDirection : 'asc';
    }

    /**
     * Clicking the sorted column flips it; a new column starts in its natural direction.
     */
    public function sort(string $column): void
    {
        $sort = HardwareSort::tryFrom($column) ?? HardwareSort::Hostname;

        $this->sortDirection = $this->listSort === $sort
            ? ($this->listSortDirection === 'asc' ? 'desc' : 'asc')
            : $sort->defaultDirection();
        $this->sortBy = $sort->value;

        unset($this->listSort, $this->listSortDirection);
        $this->resetPage();
    }

    public function render(HardwareQueries $hardware): View
    {
        return view('livewire.hardware.index', [
            'rows' => $hardware->fleet(search: $this->search, sort: $this->listSort, direction: $this->listSortDirection)
                ->paginate(config('inventory.fleet_per_page'))
                ->through(HardwareRow::for(...)),
            'devicesWithoutInventory' => $hardware->devicesWithoutInventoryCount(),
        ]);
    }
}
