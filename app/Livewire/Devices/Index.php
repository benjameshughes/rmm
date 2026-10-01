<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Device\BulkExecuteScript;
use App\Actions\Script\ExecuteScriptOnDevice;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Script;
use App\Models\Tag;
use App\Queries\AgentVersionQueries;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
final class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $groupFilter = '';

    public string $tagFilter = '';

    public array $selectedDevices = [];

    public bool $selectAll = false;

    public bool $showBulkScriptModal = false;

    public ?int $bulkScriptId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Device::class);
    }

    #[On('echo-private:devices,DeviceEnrolled')]
    #[On('echo-private:devices,DeviceUpdated')]
    #[On('echo-private:devices,LatestAgentVersionChanged')]
    public function refreshDevices(): void {}

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingGroupFilter(): void
    {
        $this->resetPage();
    }

    public function updatingTagFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSelectAll(): void
    {
        $this->selectedDevices = $this->selectAll
            ? $this->query()->pluck('id')->map(fn ($id) => (string) $id)->toArray()
            : [];
    }

    public function selectByGroup(int $groupId): void
    {
        $this->selectedDevices = Device::query()
            ->where('device_group_id', $groupId)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->toArray();
    }

    public function selectByTag(int $tagId): void
    {
        $this->selectedDevices = Device::query()
            ->whereHas('tags', fn (Builder $q) => $q->where('tags.id', $tagId))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->toArray();
    }

    public function clearSelection(): void
    {
        $this->selectedDevices = [];
        $this->selectAll = false;
    }

    public function bulkRestart(BulkExecuteScript $action): void
    {
        $this->bulkRunSystemScript($action, 'restart');
    }

    public function bulkPowerOff(BulkExecuteScript $action): void
    {
        $this->bulkRunSystemScript($action, 'shutdown');
    }

    public function bulkRunScript(BulkExecuteScript $action): void
    {
        abort_unless($this->bulkScriptId !== null, 422);

        $script = Script::findOrFail($this->bulkScriptId);
        $action($script, $this->authorizedSelectedDevices(), auth()->user());

        $this->showBulkScriptModal = false;
        $this->reset('bulkScriptId');
        $this->clearSelection();
        $this->dispatch('command-queued');
    }

    public function updateOutdatedAgents(BulkExecuteScript $action, AgentVersionQueries $agentVersions): void
    {
        $devices = $agentVersions->outdatedDevices()
            ->each(fn (Device $device) => $this->authorize('runCommands', $device));

        $action(Script::findSystem('update-agent'), $devices, auth()->user());
        $this->dispatch('command-queued');
    }

    public function powerOff(Device $device, ExecuteScriptOnDevice $action): void
    {
        $this->runSystemScript($action, $device, 'shutdown');
    }

    public function restart(Device $device, ExecuteScriptOnDevice $action): void
    {
        $this->runSystemScript($action, $device, 'restart');
    }

    public function checkForUpdates(Device $device, ExecuteScriptOnDevice $action): void
    {
        $this->runSystemScript($action, $device, 'windows-update');
    }

    public function render(AgentVersionQueries $agentVersions): View
    {
        $devices = $this->query()->paginate(12);

        return view('livewire.devices.index', [
            'devices' => $devices,
            'latestAgentVersion' => $agentVersions->latest(),
            'outdatedAgentCount' => $agentVersions->outdatedDevices()->count(),
            'groups' => DeviceGroup::query()->orderBy('name')->get(),
            'tags' => Tag::query()->orderBy('name')->get(),
            'scripts' => Script::query()->orderBy('name')->get(),
        ]);
    }

    private function runSystemScript(ExecuteScriptOnDevice $action, Device $device, string $slug): void
    {
        $this->authorize('runCommands', $device);
        $action(Script::findSystem($slug), $device, auth()->user());
        $this->dispatch('command-queued');
    }

    private function bulkRunSystemScript(BulkExecuteScript $action, string $slug): void
    {
        $action(Script::findSystem($slug), $this->authorizedSelectedDevices(), auth()->user());
        $this->clearSelection();
        $this->dispatch('command-queued');
    }

    /** @return Collection<int, Device> */
    private function authorizedSelectedDevices(): Collection
    {
        return Device::query()
            ->whereIn('id', $this->selectedDevices)
            ->get()
            ->each(fn (Device $device) => $this->authorize('runCommands', $device));
    }

    protected function query(): Builder
    {
        return Device::query()
            ->with(['latestMetric', 'group', 'tags'])
            ->when($this->search !== '', fn (Builder $q) => $q->where(function (Builder $searchQuery): void {
                $searchQuery->where('hostname', 'like', '%'.$this->search.'%')
                    ->orWhere('last_ip', 'like', '%'.$this->search.'%')
                    ->orWhere('os', 'like', '%'.$this->search.'%');
            }))
            ->when($this->groupFilter !== '', fn (Builder $q) => $q->where('device_group_id', $this->groupFilter))
            ->when($this->tagFilter !== '', fn (Builder $q) => $q->whereHas('tags', fn (Builder $tq) => $tq->where('tags.id', $this->tagFilter)))
            ->latest('last_seen');
    }
}
