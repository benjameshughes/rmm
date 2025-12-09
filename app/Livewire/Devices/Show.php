<?php

namespace App\Livewire\Devices;

use App\Models\Device;
use App\Models\DeviceCommand;
use Livewire\Attributes\Layout;
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

    public function powerOff(): void
    {
        $this->queueCommand('Stop-Computer -Force', 'powershell');
    }

    public function restart(): void
    {
        $this->queueCommand('Restart-Computer -Force', 'powershell');
    }

    public function logOff(): void
    {
        $this->queueCommand('logoff', 'cmd');
    }

    public function checkForUpdates(): void
    {
        $this->queueCommand('Get-WindowsUpdate -Install -AcceptAll -AutoReboot', 'powershell');
    }

    public function render(): mixed
    {
        $metrics = $this->device->metrics()->latest('recorded_at')->paginate(10);
        $recentCommands = $this->device->commands()->latest('queued_at')->limit(10)->get();

        return view('livewire.devices.show', [
            'device' => $this->device,
            'metrics' => $metrics,
            'recentCommands' => $recentCommands,
        ]);
    }

    protected function queueCommand(string $scriptContent, string $scriptType): void
    {
        if (! auth()->check()) {
            abort(401);
        }

        DeviceCommand::create([
            'device_id' => $this->device->id,
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
