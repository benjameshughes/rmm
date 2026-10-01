<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Device\AssignDeviceGroup;
use App\Actions\Device\QueueDeviceCommand;
use App\Actions\Device\SyncDeviceTags;
use App\Actions\Script\ExecuteScriptOnDevice;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Script;
use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
final class Show extends Component
{
    use WithPagination;

    public Device $device;

    public bool $showScriptModal = false;

    public ?int $selectedScriptId = null;

    public ?string $selectedGroupId = null;

    public array $selectedTagIds = [];

    public function mount(Device $device): void
    {
        $this->device = $device->load(['latestMetric', 'group', 'tags']);
        $this->selectedGroupId = $device->device_group_id ? (string) $device->device_group_id : '';
        $this->selectedTagIds = $device->tags->pluck('id')->map(fn ($id) => (string) $id)->toArray();
    }

    public function updatedSelectedGroupId(AssignDeviceGroup $action): void
    {
        $group = $this->selectedGroupId ? DeviceGroup::find($this->selectedGroupId) : null;
        $action($this->device, $group);
        $this->device->refresh();
    }

    public function updatedSelectedTagIds(SyncDeviceTags $action): void
    {
        $action($this->device, collect($this->selectedTagIds)->map(fn ($id) => (int) $id)->toArray());
        $this->device->load('tags');
    }

    public function powerOff(QueueDeviceCommand $action): void
    {
        abort_unless(auth()->check(), 401);
        $action($this->device, 'Stop-Computer -Force', 'powershell', auth()->user());
        $this->dispatch('command-queued');
    }

    public function restart(QueueDeviceCommand $action): void
    {
        abort_unless(auth()->check(), 401);
        $action($this->device, 'Restart-Computer -Force', 'powershell', auth()->user());
        $this->dispatch('command-queued');
    }

    public function logOff(QueueDeviceCommand $action): void
    {
        abort_unless(auth()->check(), 401);
        $action($this->device, $this->logOffAllUsersScript(), 'powershell', auth()->user());
        $this->dispatch('command-queued');
    }

    /**
     * The agent runs as SYSTEM in session 0, so a bare `logoff` targets the
     * service session. Each interactive session has to be logged off by ID.
     */
    private function logOffAllUsersScript(): string
    {
        return <<<'POWERSHELL'
            $sessionIds = quser 2>$null | Select-Object -Skip 1 | ForEach-Object {
                if ($_ -match '\s(\d+)\s+(Active|Disc)') { $Matches[1] }
            }
            if (-not $sessionIds) { Write-Output 'No users are logged in.'; exit 0 }
            $sessionIds | ForEach-Object { logoff $_; Write-Output "Logged off session $_" }
            POWERSHELL;
    }

    public function checkForUpdates(QueueDeviceCommand $action): void
    {
        abort_unless(auth()->check(), 401);
        $action($this->device, 'Get-WindowsUpdate -Install -AcceptAll -AutoReboot', 'powershell', auth()->user());
        $this->dispatch('command-queued');
    }

    public function resetEnrolment(): void
    {
        abort_unless(auth()->check(), 401);

        $this->device->resetEnrolment();
        $this->device->refresh();

        Log::warning('device.enrolment_reset', [
            'device_id' => $this->device->id,
            'hostname' => $this->device->hostname,
            'user_id' => auth()->id(),
        ]);

        $this->dispatch('notify', message: 'Enrolment reset. Re-approve the device after running "rmm reenroll" on it.');
    }

    public function runScript(ExecuteScriptOnDevice $action): void
    {
        abort_unless(auth()->check(), 401);
        abort_unless($this->selectedScriptId !== null, 422);

        $script = Script::findOrFail($this->selectedScriptId);
        $action($script, $this->device, auth()->user());

        $this->showScriptModal = false;
        $this->reset('selectedScriptId');
        $this->dispatch('command-queued');
    }

    public function render(): View
    {
        $metrics = $this->device->metrics()->latest('recorded_at')->paginate(10);
        $recentCommands = $this->device->commands()->latest('queued_at')->limit(10)->get();

        return view('livewire.devices.show', [
            'device' => $this->device,
            'metrics' => $metrics,
            'recentCommands' => $recentCommands,
            'scripts' => Script::query()->orderBy('name')->get(),
            'allGroups' => DeviceGroup::query()->orderBy('name')->get(),
            'allTags' => Tag::query()->orderBy('name')->get(),
        ]);
    }
}
