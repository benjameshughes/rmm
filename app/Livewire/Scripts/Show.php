<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\DeviceStatus;
use App\Livewire\Concerns\CancelsCommands;
use App\Livewire\Concerns\EntersScriptParameterValues;
use App\Models\Device;
use App\Models\Script;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Show extends Component
{
    use CancelsCommands;
    use EntersScriptParameterValues;

    public Script $script;

    public bool $showExecuteModal = false;

    public string $deviceSearch = '';

    public ?int $selectedDeviceId = null;

    public function mount(Script $script): void
    {
        $this->script = $script;
        $this->fillParameterValues();
    }

    #[On('echo-private:devices,CommandUpdated')]
    public function refreshExecutions(): void {}

    public function executeOnDevice(ExecuteScriptOnDevice $action, ValidateScriptParameterValues $validateParameters): void
    {
        abort_unless($this->selectedDeviceId !== null, 422);

        $device = Device::findOrFail($this->selectedDeviceId);
        $this->authorize('runCommands', $device);

        $action($this->script, $device, auth()->user(), parameters: $this->validatedParameterValues($validateParameters, $this->script));

        $this->showExecuteModal = false;
        $this->reset('selectedDeviceId', 'deviceSearch');
        $this->fillParameterValues();
        $this->dispatch('command-queued');
    }

    protected function parameterScript(): ?Script
    {
        return $this->script;
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
            ->acceptsCommands()
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
