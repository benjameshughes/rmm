<?php

namespace App\Livewire\Devices;

use App\Models\Device;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): mixed
    {
        $devices = $this->query()->paginate(12);

        return view('livewire.devices.index', [
            'devices' => $devices,
        ]);
    }

    protected function query(): Builder
    {
        return Device::query()
            ->with(['latestMetric'])
            ->when($this->search !== '', function (Builder $q): void {
                $q->where('hostname', 'like', '%'.$this->search.'%')
                    ->orWhere('last_ip', 'like', '%'.$this->search.'%')
                    ->orWhere('os', 'like', '%'.$this->search.'%');
            })
            ->latest('last_seen');
    }
}

