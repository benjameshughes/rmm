<?php

namespace App\Livewire\Devices;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Database\Eloquent\Builder;
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

    public function powerOff(Device $device): void
    {
        $this->queueCommand($device, 'Stop-Computer -Force', 'powershell');
    }

    public function restart(Device $device): void
    {
        $this->queueCommand($device, 'Restart-Computer -Force', 'powershell');
    }

    public function checkForUpdates(Device $device): void
    {
        $this->queueCommand($device, 'Get-WindowsUpdate -Install -AcceptAll -AutoReboot', 'powershell');
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

    protected function queueCommand(Device $device, string $scriptContent, string $scriptType): void
    {
        if (! auth()->check()) {
            abort(401);
        }

        DeviceCommand::create([
            'device_id' => $device->id,
            'script_content' => $scriptContent,
            'script_type' => $scriptType,
            'status' => DeviceCommand::STATUS_PENDING,
            'queued_at' => now(),
            'queued_by' => auth()->id(),
            'timeout_seconds' => 300,
        ]);

        $this->dispatch('command-queued');
    }
}
