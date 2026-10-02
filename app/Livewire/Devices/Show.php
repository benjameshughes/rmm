<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Device\AssignDeviceGroup;
use App\Actions\Device\SyncDeviceTags;
use App\Actions\Device\WakeDevice;
use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Livewire\Concerns\EntersScriptParameterValues;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Script;
use App\Models\Tag;
use App\Queries\AgentVersionQueries;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
final class Show extends Component
{
    use EntersScriptParameterValues;
    use WithPagination;

    public Device $device;

    public bool $showScriptModal = false;

    public ?int $selectedScriptId = null;

    public ?string $selectedGroupId = null;

    public array $selectedTagIds = [];

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device->load([...self::latestMetricRelations(), 'group', 'tags']);
        $this->syncSelectionsFromDevice();
    }

    #[On('echo-private:devices.{device.id},DeviceUpdated')]
    public function refreshDevice(): void
    {
        $this->device->refresh()->load(self::latestMetricRelations());
        $this->syncSelectionsFromDevice();
    }

    #[On('echo-private:devices.{device.id},CommandUpdated')]
    public function refreshCommands(): void {}

    #[On('echo-private:devices,LatestAgentVersionChanged')]
    public function refreshLatestAgentVersion(): void {}

    public function updatedSelectedGroupId(AssignDeviceGroup $action): void
    {
        $this->authorize('manageGroupsAndTags', $this->device);

        $group = $this->selectedGroupId ? DeviceGroup::find($this->selectedGroupId) : null;
        $action($this->device, $group);
        $this->device->refresh();
    }

    public function updatedSelectedScriptId(): void
    {
        $this->fillParameterValues();
    }

    public function updatedSelectedTagIds(SyncDeviceTags $action): void
    {
        $this->authorize('manageGroupsAndTags', $this->device);

        $action($this->device, collect($this->selectedTagIds)->map(fn ($id) => (int) $id)->toArray());
        $this->device->load('tags');
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
        $this->authorize('wake', $this->device);

        $action($this->device);

        Flux::toast(text: 'It shows Online once the agent checks in, usually within a minute or two.', heading: "Wake packet sent to {$this->device->hostname}", variant: 'success');
    }

    public function resetEnrolment(): void
    {
        $this->authorize('resetEnrolment', $this->device);

        $this->device->resetEnrolment();
        $this->device->refresh();

        $this->dispatch('notify', message: 'Enrolment reset. Re-approve the device after running "rmm reenroll" on it.');
    }

    public function runScript(ExecuteScriptOnDevice $action, ValidateScriptParameterValues $validateParameters): void
    {
        $this->authorize('runCommands', $this->device);
        abort_unless($this->selectedScriptId !== null, 422);

        $script = Script::findOrFail($this->selectedScriptId);
        $action($script, $this->device, auth()->user(), parameters: $this->validatedParameterValues($validateParameters, $script));

        $this->showScriptModal = false;
        $this->reset('selectedScriptId', 'parameterValues');
        $this->dispatch('command-queued');
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

    /** @return array<int, string> Everything the device page reads from the latest report */
    private static function latestMetricRelations(): array
    {
        return ['latestMetric.diskMetrics', 'latestMetric.networkMetrics', 'latestMetric.appMetrics'];
    }

    private function syncSelectionsFromDevice(): void
    {
        $this->selectedGroupId = $this->device->device_group_id ? (string) $this->device->device_group_id : '';
        $this->selectedTagIds = $this->device->tags->pluck('id')->map(fn ($id) => (string) $id)->toArray();
    }

    public function render(AgentVersionQueries $agentVersions): View
    {
        $metrics = $this->device->metrics()->latest('recorded_at')->paginate(10);
        $recentCommands = $this->device->commands()->with(['script', 'queuedBy'])->latest('queued_at')->limit(10)->get();

        return view('livewire.devices.show', [
            'device' => $this->device,
            'latestAgentVersion' => $agentVersions->latest(),
            'apiKeyState' => $this->device->apiKeyState(),
            'apiKeyStateDetail' => $this->device->apiKeyStateDetail(),
            'diskUsage' => $this->device->diskUsage(),
            'metrics' => $metrics,
            'recentCommands' => $recentCommands,
            'scripts' => Script::query()->orderBy('name')->get(),
            'allGroups' => DeviceGroup::query()->orderBy('name')->get(),
            'allTags' => Tag::query()->orderBy('name')->get(),
        ]);
    }
}
