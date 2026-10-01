<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Script;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Show extends Component
{
    public Script $script;

    public bool $showExecuteModal = false;

    public string $deviceSearch = '';

    public ?int $selectedDeviceId = null;

    public function mount(Script $script): void
    {
        $this->script = $script;
    }

    public function executeOnDevice(ExecuteScriptOnDevice $action): void
    {
        abort_unless(auth()->check(), 401);
        abort_unless($this->selectedDeviceId !== null, 422);

        $device = Device::findOrFail($this->selectedDeviceId);

        $action($this->script, $device, auth()->user());

        $this->showExecuteModal = false;
        $this->reset('selectedDeviceId', 'deviceSearch');
        $this->dispatch('command-queued');
    }

    public function render(): View
    {
        $recentCommands = $this->script->commands()
            ->with('device')
            ->latest('queued_at')
            ->limit(10)
            ->get();

        $availableDevices = Device::query()
            ->where('status', DeviceStatus::Active)
            ->when($this->deviceSearch !== '', fn ($q) => $q->where('hostname', 'like', '%'.$this->deviceSearch.'%'))
            ->orderBy('hostname')
            ->limit(20)
            ->get();

        return view('livewire.scripts.show', [
            'recentCommands' => $recentCommands,
            'availableDevices' => $availableDevices,
        ]);
    }
}
