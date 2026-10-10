<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Device\RunAdHocCommand;
use App\Actions\Device\WakeDevice;
use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\ScriptType;
use App\Exceptions\ScriptCannotBeRunDirectly;
use App\Livewire\Concerns\EntersScriptParameterValues;
use App\Livewire\Concerns\WakesDevices;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Queries\AgentVersionQueries;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The persistent device header: who it is, whether it is up, and every action you can take on it.
 */
final class Header extends Component
{
    use EntersScriptParameterValues;
    use WakesDevices;

    public Device $device;

    public bool $showScriptModal = false;

    public ?int $selectedScriptId = null;

    public bool $showCommandModal = false;

    public string $commandText = '';

    public string $commandType = '';

    public int $commandTimeoutSeconds = 0;

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
        $this->resetCommandForm();
    }

    #[On('echo-private:devices.{device.id},DeviceUpdated')]
    #[On('echo-private:devices.{device.id},PrintersReported')]
    public function refreshDevice(): void
    {
        $this->device->refresh();
    }

    #[On('echo-private:devices,LatestAgentVersionChanged')]
    public function refreshLatestAgentVersion(): void {}

    /**
     * Re-renders so Run Command shows the ad-hoc command in flight, and goes back once it finishes.
     */
    #[On('echo-private:devices.{device.id},CommandUpdated')]
    #[On('echo-private:devices.{device.id},CommandProgressed')]
    #[On('command-queued')]
    public function refreshCommands(): void {}

    public function updatedSelectedScriptId(): void
    {
        $this->fillParameterValues();
    }

    public function powerOff(ExecuteScriptOnDevice $action): void
    {
        $this->runSystemScript($action, 'shutdown');
    }

    public function restart(ExecuteScriptOnDevice $action): void
    {
        $this->runSystemScript($action, 'restart');
    }

    public function logOff(ExecuteScriptOnDevice $action): void
    {
        $this->runSystemScript($action, 'log-off');
    }

    public function checkForUpdates(ExecuteScriptOnDevice $action): void
    {
        $this->runSystemScript($action, 'windows-update');
    }

    public function updateAgent(ExecuteScriptOnDevice $action): void
    {
        $this->runSystemScript($action, 'update-agent');
    }

    public function wake(WakeDevice $action): void
    {
        $this->wakeDevice($this->device, $action);
    }

    public function runScript(ExecuteScriptOnDevice $action, ValidateScriptParameterValues $validateParameters): void
    {
        $this->authorize('runCommands', $this->device);
        abort_unless($this->selectedScriptId !== null, 422);

        $script = Script::findOrFail($this->selectedScriptId);
        throw_if($script->is_internal, ScriptCannotBeRunDirectly::internal($script));

        $action($script, $this->device, auth()->user(), parameters: $this->validatedParameterValues($validateParameters, $script));

        $this->showScriptModal = false;
        $this->reset('selectedScriptId', 'parameterValues');
        $this->dispatch('command-queued');
    }

    public function runAdHocCommand(RunAdHocCommand $action): void
    {
        $this->authorize('runAdHocCommand', $this->device);

        $limits = config('commands.ad_hoc');

        $this->validate([
            'commandText' => ['required', 'string', "max:{$limits['max_length']}"],
            'commandType' => ['required', Rule::enum(ScriptType::class)->only($this->commandTypes)],
            'commandTimeoutSeconds' => ['required', 'integer', "min:{$limits['timeout_seconds']['min']}", "max:{$limits['timeout_seconds']['max']}"],
        ], [
            'commandText.required' => 'Type the command to run.',
            'commandText.max' => 'Commands may not be longer than :max characters.',
            'commandType.required' => 'Choose a shell this device can run.',
            'commandType.enum' => 'Choose a shell this device can run.',
            'commandTimeoutSeconds.required' => 'Set a timeout in seconds.',
            'commandTimeoutSeconds.integer' => 'Set a timeout in whole seconds.',
            'commandTimeoutSeconds.min' => 'The timeout must be at least :min seconds.',
            'commandTimeoutSeconds.max' => 'The timeout may not be more than :max seconds.',
        ]);

        $action($this->device, auth()->user(), $this->commandText, ScriptType::from($this->commandType), $this->commandTimeoutSeconds);

        $this->showCommandModal = false;
        $this->resetCommandForm();
        $this->dispatch('command-queued');

        Flux::toast(text: 'Open it on the Commands tab to see the output once the agent picks it up.', heading: "Command queued for {$this->device->hostname}", variant: 'success');
    }

    /**
     * Shells the device's platform can run, the first being the default.
     *
     * @return array<int, ScriptType>
     */
    #[Computed]
    public function commandTypes(): array
    {
        return $this->device->platform()->adHocScriptTypes();
    }

    protected function parameterScript(): ?Script
    {
        return $this->selectedScriptId === null ? null : Script::find($this->selectedScriptId);
    }

    private function runSystemScript(ExecuteScriptOnDevice $action, string $slug): void
    {
        $this->authorize('runCommands', $this->device);
        $action(Script::findSystem($slug), $this->device, auth()->user());
        $this->dispatch('command-queued');
    }

    /**
     * The newest typed command still queued or running, for the busy Run Command button.
     */
    private function inFlightAdHocCommand(): ?DeviceCommand
    {
        return $this->device->isMonitorOnly ? null : $this->device->inFlightCommands()->whereNull('script_id')->latest('id')->first();
    }

    private function resetCommandForm(): void
    {
        $this->resetValidation(['commandText', 'commandType', 'commandTimeoutSeconds']);
        $this->commandText = '';
        $this->commandType = $this->commandTypes[0]->value;
        $this->commandTimeoutSeconds = config('commands.ad_hoc.timeout_seconds.default');
    }

    public function render(AgentVersionQueries $agentVersions): View
    {
        return view('livewire.devices.header', [
            'latestAgentVersion' => $agentVersions->latest(),
            'statusLabel' => $this->device->statusLabel(),
            'statusColor' => $this->device->statusColor(),
            'operatingSystem' => $this->device->operatingSystem(),
            'printerProblem' => $this->device->printerProblemLabel(),
            'scripts' => $this->showScriptModal ? Script::query()->runnableDirectly()->orderBy('name')->get() : collect(),
            'adHocCommand' => $this->inFlightAdHocCommand(),
        ]);
    }
}
