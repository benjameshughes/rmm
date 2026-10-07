<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Backup\QueueBackupScript;
use App\Enums\BackupScript;
use App\Enums\DeviceStatus;
use App\Models\Device;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Restores one of this PC's snapshots, onto this PC or any other Windows PC
 * that takes commands. The restore runs on the chosen PC, which is handed
 * this PC's repository credentials only when its agent fetches the command.
 */
final class RestoreBackup extends Component
{
    public Device $device;

    public bool $showModal = false;

    public string $snapshotId = '';

    public string $includePath = '';

    public string $target = '';

    public ?int $targetDeviceId = null;

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
    }

    #[On('restore-snapshot')]
    public function open(string $snapshotId): void
    {
        $this->resetValidation();
        $this->reset('includePath', 'target');
        $this->snapshotId = $snapshotId;
        $this->targetDeviceId = $this->device->id;
        $this->showModal = true;
    }

    public function restore(QueueBackupScript $queue): void
    {
        abort_unless($this->device->canBackUp(), 422);

        $this->validate([
            'snapshotId' => ['required', Rule::in(['latest', ...$this->device->backupSnapshots()->pluck('snapshot_id')])],
            'targetDeviceId' => ['required', 'integer', Rule::in($this->targets->modelKeys())],
            'includePath' => ['nullable', 'string', 'max:500', 'regex:/^[A-Za-z]:\\\\/', 'not_regex:/[<>"|?*\x00-\x1f]/'],
            'target' => ['nullable', 'string', 'max:500', 'regex:/^[A-Za-z]:\\\\[^<>:"|?*\x00-\x1f]+$/'],
        ], [
            'snapshotId.in' => 'Choose a snapshot from the list. Refresh snapshots if it is missing.',
            'targetDeviceId.required' => 'Choose the PC to restore onto.',
            'targetDeviceId.in' => 'Choose a Windows PC that takes commands.',
            'includePath.regex' => 'Write the path as it was on the PC, for example C:\\Users\\anna\\Documents.',
            'includePath.not_regex' => 'The path contains characters Windows does not allow.',
            'target.regex' => 'Use a folder path on the PC, for example C:\\Restore\\Anna.',
        ]);

        $targetDevice = $this->targets->find($this->targetDeviceId);
        $this->authorize('runCommands', $targetDevice);

        $queue(BackupScript::Restore, $targetDevice, auth()->user(), [
            'SnapshotId' => $this->snapshotId,
            'IncludePath' => $this->includePath,
            'Target' => $this->target,
            'SourceDevice' => $this->device->id,
        ], attribute: 'restore');

        $this->showModal = false;
        $this->dispatch('command-queued');

        Flux::toast(text: "Restoring {$this->device->hostname}'s backup onto {$targetDevice->hostname}. Its result shows on that PC's Commands tab.", heading: BackupScript::Restore->queuedHeading(), variant: 'success');
    }

    /**
     * Windows PCs that are approved and take commands, this one first.
     *
     * @return Collection<int, Device>
     */
    #[Computed]
    public function targets(): Collection
    {
        return Device::query()
            ->where('status', DeviceStatus::Active)
            ->runsWindows()
            ->acceptsCommands()
            ->orderByRaw('id = ? desc', [$this->device->id])
            ->orderBy('hostname')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.devices.restore-backup', [
            'targets' => $this->showModal ? $this->targets : collect(),
            'defaultTarget' => 'C:\\Restore\\'.now()->format('Ymd-Hi'),
        ]);
    }
}
