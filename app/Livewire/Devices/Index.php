<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Device\BulkExecuteScript;
use App\Actions\Device\WakeDevice;
use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\DeviceListFilter;
use App\Enums\DeviceListSort;
use App\Livewire\Concerns\EntersScriptParameterValues;
use App\Livewire\Concerns\WakesDevices;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Script;
use App\Models\Tag;
use App\Queries\AgentVersionQueries;
use App\Queries\DeviceListQueries;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
final class Index extends Component
{
    use EntersScriptParameterValues;
    use WakesDevices;
    use WithPagination;

    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url(as: 'sort')]
    public string $sortBy = DeviceListSort::Hostname->value;

    #[Url(as: 'direction')]
    public string $sortDirection = 'asc';

    #[Locked]
    public int $renderedAt = 0;

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

    /**
     * Every redraw, whatever caused it, restarts the quiet period for routine reports.
     */
    public function rendering(): void
    {
        $this->renderedAt = now()->getTimestamp();
    }

    /**
     * Every metrics report broadcasts, so routine ones only redraw a list that has
     * gone stale; a real state change (status, power, online or offline) redraws at once.
     *
     * @param  array{deviceId?: int, status?: string, isStateChange?: bool}  $event
     */
    #[On('echo-private:devices,DeviceUpdated')]
    public function refreshFromDeviceUpdate(array $event = []): void
    {
        $isStale = now()->getTimestamp() - $this->renderedAt >= config('devices.list.refresh_seconds');

        if (! ($event['isStateChange'] ?? true) && ! $isStale) {
            $this->skipRender();
        }
    }

    #[On('echo-private:devices,DeviceEnrolled')]
    #[On('echo-private:devices,LatestAgentVersionChanged')]
    #[On('echo-private:devices,AlertChanged')]
    public function refreshDevices(): void {}

    /**
     * A hand-edited sort falls back to hostname, A to Z.
     */
    #[Computed]
    public function listSort(): DeviceListSort
    {
        return DeviceListSort::tryFrom($this->sortBy) ?? DeviceListSort::Hostname;
    }

    #[Computed]
    public function listSortDirection(): string
    {
        return in_array($this->sortDirection, ['asc', 'desc'], true) ? $this->sortDirection : 'asc';
    }

    /**
     * Clicking the sorted column flips it; a new column starts in its natural direction.
     */
    public function sort(string $column): void
    {
        $sort = DeviceListSort::tryFrom($column) ?? DeviceListSort::Hostname;

        $this->sortDirection = $this->listSort === $sort
            ? ($this->listSortDirection === 'asc' ? 'desc' : 'asc')
            : $sort->defaultDirection();
        $this->sortBy = $sort->value;

        unset($this->listSort, $this->listSortDirection);
        $this->resetPage();
    }

    /**
     * A hand-edited query string shows the whole fleet rather than erroring.
     */
    #[Computed]
    public function listFilter(): ?DeviceListFilter
    {
        return DeviceListFilter::tryFrom($this->statusFilter);
    }

    /**
     * The summary cards toggle: clicking the active one shows the whole fleet again.
     */
    public function filterByStatus(string $status): void
    {
        $this->statusFilter = $this->statusFilter === $status ? '' : $status;
        unset($this->listFilter);
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'groupFilter', 'tagFilter', 'statusFilter');
        unset($this->listFilter);
        $this->resetPage();
    }

    public function wake(Device $device, WakeDevice $action): void
    {
        $this->wakeDevice($device, $action);
    }

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

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedBulkScriptId(): void
    {
        $this->fillParameterValues();
    }

    public function updatedSelectAll(DeviceListQueries $deviceList): void
    {
        $this->selectedDevices = $this->selectAll
            ? $this->query($deviceList)->pluck('id')->map(fn ($id) => (string) $id)->toArray()
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

    public function bulkRunScript(BulkExecuteScript $action, ValidateScriptParameterValues $validateParameters): void
    {
        abort_unless($this->bulkScriptId !== null, 422);

        $script = Script::findOrFail($this->bulkScriptId);
        $parameters = $this->validatedParameterValues($validateParameters, $script);
        $devices = $this->authorizedSelectedDevices();
        $queued = $action($script, $devices, auth()->user(), $parameters);

        $this->showBulkScriptModal = false;
        $this->reset('bulkScriptId', 'parameterValues');
        $this->clearSelection();
        $this->dispatch('command-queued');

        if ($queued < $devices->count()) {
            Flux::toast(
                text: 'Inactive and monitor-only devices, and devices whose agent is older than '.config('agent.parameters_min_version').', were skipped.',
                heading: "Queued on {$queued} of {$devices->count()} ".Str::plural('device', $devices->count()),
                variant: 'warning',
            );
        }
    }

    public function updateOutdatedAgents(BulkExecuteScript $action, AgentVersionQueries $agentVersions): void
    {
        $action(Script::findSystem('update-agent'), $this->authorizeCommandableDevices($agentVersions->outdatedDevices()), auth()->user());
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

    public function render(AgentVersionQueries $agentVersions, DeviceListQueries $deviceList): View
    {
        $summary = $deviceList->summary();

        return view('livewire.devices.index', [
            'devices' => $this->query($deviceList)->paginate(config('devices.list.per_page')),
            'summary' => $summary,
            'isFiltered' => $this->search !== '' || $this->groupFilter !== '' || $this->tagFilter !== '' || $this->listFilter !== null,
            'latestAgentVersion' => $agentVersions->latest(),
            'outdatedAgentCount' => $summary['outdated'],
            'groups' => DeviceGroup::query()->orderBy('name')->get(),
            'tags' => Tag::query()->orderBy('name')->get(),
            'scripts' => Script::query()->orderBy('name')->get(),
        ]);
    }

    protected function parameterScript(): ?Script
    {
        return $this->bulkScriptId === null ? null : Script::find($this->bulkScriptId);
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
        return $this->authorizeCommandableDevices(Device::query()->whereIn('id', $this->selectedDevices)->get());
    }

    /**
     * Monitor-only devices pass through unchecked for the bulk action to skip,
     * so one Linux server in a selection does not refuse the whole batch.
     *
     * @param  Collection<int, Device>  $devices
     * @return Collection<int, Device>
     */
    private function authorizeCommandableDevices(Collection $devices): Collection
    {
        $devices->reject(fn (Device $device): bool => $device->isMonitorOnly)
            ->each(fn (Device $device) => $this->authorize('runCommands', $device));

        return $devices;
    }

    protected function query(DeviceListQueries $deviceList): Builder
    {
        return $deviceList->rows()
            ->when($this->listFilter !== null, fn (Builder $q) => $deviceList->filter($q, $this->listFilter))
            ->when($this->search !== '', fn (Builder $q) => $q->where(function (Builder $searchQuery): void {
                $searchQuery->where('hostname', 'like', '%'.$this->search.'%')
                    ->orWhere('last_ip', 'like', '%'.$this->search.'%')
                    ->orWhere('os', 'like', '%'.$this->search.'%');
            }))
            ->when($this->groupFilter !== '', fn (Builder $q) => $q->where('device_group_id', $this->groupFilter))
            ->when($this->tagFilter !== '', fn (Builder $q) => $q->whereHas('tags', fn (Builder $tq) => $tq->where('tags.id', $this->tagFilter)))
            ->tap(fn (Builder $q) => $deviceList->sort($q, $this->listSort, $this->listSortDirection));
    }
}
