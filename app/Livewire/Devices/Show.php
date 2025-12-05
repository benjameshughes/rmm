<?php

namespace App\Livewire\Devices;

use App\Models\Device;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Show extends Component
{
    use WithPagination;

    public Device $device;

    public function mount(Device $device): void
    {
        $this->device = $device->load('latestMetric');
    }

    public function render(): mixed
    {
        $metrics = $this->device->metrics()->latest('recorded_at')->paginate(10);

        return view('livewire.devices.show', [
            'device' => $this->device,
            'metrics' => $metrics,
        ]);
    }
}

