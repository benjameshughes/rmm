<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Device\BulkExecuteScript;
use App\Actions\Script\ExecuteScriptOnDevice;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Script;
use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
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
        abort_unless(auth()->check(), 401);
        abort_unless($this->bulkScriptId !== null, 422);

        $script = Script::findOrFail($this->bulkScriptId);
        $devices = Device::whereIn('id', $this->selectedDevices)->get();
        $action($script, $devices, auth()->user());

        $this->showBulkScriptModal = false;
        $this->reset('bulkScriptId');
        $this->clearSelection();
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

    public function render(): View
    {
        $devices = $this->query()->paginate(12);

        return view('livewire.devices.index', [
            'devices' => $devices,
            'groups' => DeviceGroup::query()->orderBy('name')->get(),
            'tags' => Tag::query()->orderBy('name')->get(),
            'scripts' => Script::query()->orderBy('name')->get(),
        ]);
    }

    private function runSystemScript(ExecuteScriptOnDevice $action, Device $device, string $slug): void
    {
        abort_unless(auth()->check(), 401);
        $action(Script::findSystem($slug), $device, auth()->user());
        $this->dispatch('command-queued');
    }

    private function bulkRunSystemScript(BulkExecuteScript $action, string $slug): void
    {
        abort_unless(auth()->check(), 401);
        $devices = Device::whereIn('id', $this->selectedDevices)->get();
        $action(Script::findSystem($slug), $devices, auth()->user());
        $this->clearSelection();
        $this->dispatch('command-queued');
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
