<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\Actions\Device\BulkExecuteScript;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\DeviceStatus;
use App\Exceptions\ScriptCannotBeRunDirectly;
use App\Livewire\Concerns\CancelsCommands;
use App\Livewire\Concerns\EntersScriptParameterValues;
use App\Models\Device;
use App\Models\Script;
use Flux\Flux;
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

    /** @var array<int, int|string> */
    public array $selectedDeviceIds = [];

    public function mount(Script $script): void
    {
        $this->script = $script;
        $this->fillParameterValues();
    }

    #[On('echo-private:devices,CommandUpdated')]
    public function refreshExecutions(): void {}

    /**
     * Devices that cannot run it (monitor-only, or on an agent too old for a
     * parameterised script) are skipped, so the toast says how many it queued on.
     */
    public function executeOnDevices(BulkExecuteScript $action, ValidateScriptParameterValues $validateParameters): void
    {
        throw_if($this->script->is_internal, ScriptCannotBeRunDirectly::internal($this->script));
        abort_if($this->selectedDeviceIds === [], 422);

        $devices = Device::query()->whereIn('id', $this->selectedDeviceIds)->get()
            ->each(fn (Device $device) => $this->authorize('runCommands', $device));

        $queued = $action($this->script, $devices, auth()->user(), $this->validatedParameterValues($validateParameters, $this->script));

        $this->showExecuteModal = false;
        $this->reset('selectedDeviceIds');
        $this->fillParameterValues();
        $this->dispatch('command-queued');

        Flux::toast(text: "Queued on {$queued} of {$devices->count()} ".str('device')->plural($devices->count()).'.', heading: "{$this->script->name} queued", variant: 'success');
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
            ->orderBy('hostname')
            ->get();

        return view('livewire.scripts.show', [
            'recentCommands' => $recentCommands,
            'availableDevices' => $availableDevices,
        ]);
    }
}
