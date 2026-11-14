<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Device\BulkExecuteScript;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\DeviceStatus;
use App\Exceptions\ScriptCannotBeRunDirectly;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Script;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The Run modal on the script library: pick devices on the script's platform, fill
 * its parameters, queue it. Needs EntersScriptParameterValues alongside.
 */
trait RunsScriptOnDevices
{
    public bool $showExecuteModal = false;

    /** @var array<int, int|string> */
    public array $selectedDeviceIds = [];

    /** The script the Run modal is for. */
    abstract protected function scriptToRun(): ?Script;

    /**
     * Active devices that take commands and run the script's platform.
     *
     * @return Collection<int, Device>
     */
    #[Computed]
    public function runTargets(): Collection
    {
        $script = $this->scriptToRun();

        return $script === null ? collect() : Device::query()
            ->where('status', DeviceStatus::Active)
            ->acceptsCommands()
            ->runsPlatform($script->platform)
            ->orderBy('hostname')
            ->get();
    }

    /**
     * Groups holding at least one device the script can run on, for adding a whole group at once.
     *
     * @return Collection<int, DeviceGroup>
     */
    #[Computed]
    public function runTargetGroups(): Collection
    {
        $groupIds = $this->runTargets->pluck('device_group_id')->filter()->unique();

        return $groupIds->isEmpty() ? collect() : DeviceGroup::query()->whereIn('id', $groupIds)->orderBy('name')->get();
    }

    public function addGroupToRun(int $groupId): void
    {
        $this->selectedDeviceIds = collect($this->selectedDeviceIds)
            ->map(fn (int|string $id): string => (string) $id)
            ->merge($this->runTargets->where('device_group_id', $groupId)->map(fn (Device $device): string => (string) $device->id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Devices off the script's platform are dropped, and BulkExecuteScript skips the rest it
     * cannot reach, so the toast says how many of the picked devices it queued on.
     */
    public function executeOnDevices(BulkExecuteScript $action, ValidateScriptParameterValues $validateParameters): void
    {
        $script = $this->scriptToRun();
        abort_if($script === null || $this->selectedDeviceIds === [], 422);
        throw_if($script->is_internal, ScriptCannotBeRunDirectly::internal($script));

        $devices = Device::query()->whereIn('id', $this->selectedDeviceIds)->runsPlatform($script->platform)->get()
            ->each(fn (Device $device) => $this->authorize('runCommands', $device));

        $queued = $action($script, $devices, auth()->user(), $this->validatedParameterValues($validateParameters, $script));
        $picked = count($this->selectedDeviceIds);

        $this->showExecuteModal = false;
        $this->reset('selectedDeviceIds');
        $this->fillParameterValues();
        $this->dispatch('command-queued');

        Flux::toast(text: "Queued on {$queued} of {$picked} ".str('device')->plural($picked).'.', heading: "{$script->name} queued", variant: 'success');
    }

    protected function parameterScript(): ?Script
    {
        return $this->scriptToRun();
    }
}
