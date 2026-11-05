<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Backup\QueueBackupScript;
use App\Enums\DeviceStatus;
use App\Enums\RestoreMode;
use App\Models\Device;
use App\Models\StaffAccount;
use App\Queries\StaffAccountQueries;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Restores one of this PC's snapshots, onto this PC or any other Windows PC
 * that takes commands. The restore runs on the chosen PC, which is handed
 * this PC's repository credentials only when its agent fetches the command.
 * It lands in a new folder, or in the profile of a staff account that
 * targets the chosen PC, from a profile folder picked from the snapshot or
 * typed, since the old username often differs from the new one.
 */
final class RestoreBackup extends Component
{
    public Device $device;

    public bool $showModal = false;

    public string $snapshotId = '';

    public string $includePath = '';

    public string $target = '';

    public ?int $targetDeviceId = null;

    public string $mode = RestoreMode::Folder->value;

    public ?int $staffAccountId = null;

    public string $sourceProfile = '';

    private StaffAccountQueries $staffAccountQueries;

    public function boot(StaffAccountQueries $staffAccountQueries): void
    {
        $this->staffAccountQueries = $staffAccountQueries;
    }

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
    }

    #[On('restore-snapshot')]
    public function open(string $snapshotId): void
    {
        $this->resetValidation();
        $this->reset('includePath', 'target', 'mode', 'staffAccountId', 'sourceProfile');
        $this->snapshotId = $snapshotId;
        $this->targetDeviceId = $this->device->id;
        $this->showModal = true;
    }

    public function updatedTargetDeviceId(): void
    {
        $this->reset('staffAccountId');
    }

    /**
     * Offers the snapshot's profile folder named like the account, if there is
     * one and nothing has been typed yet. It is only ever a suggestion.
     */
    public function updatedStaffAccountId(): void
    {
        $account = $this->staffAccounts->find($this->staffAccountId);

        if ($account === null || $this->sourceProfile !== '') {
            return;
        }

        $names = collect([$account->username, str($account->full_name)->before(' ')->toString()])
            ->filter()
            ->map(fn (string $name): string => mb_strtolower($name));

        $this->sourceProfile = (string) $this->sourceProfiles->first(fn (string $path): bool => $names->contains(
            fn (string $name): bool => str(mb_strtolower(str($path)->afterLast('\\')->toString()))->is([$name, "{$name}.*"]),
        ));
    }

    public function restore(QueueBackupScript $queue): void
    {
        abort_unless($this->device->canBackUp(), 422);

        $this->validate([
            'mode' => ['required', Rule::enum(RestoreMode::class)],
            'snapshotId' => ['required', Rule::in(['latest', ...$this->device->backupSnapshots()->pluck('snapshot_id')])],
            'targetDeviceId' => ['required', 'integer', Rule::in($this->targets->modelKeys())],
        ], $this->messages());

        $targetDevice = $this->targets->find($this->targetDeviceId);
        $this->authorize('runCommands', $targetDevice);

        $restoreMode = RestoreMode::from($this->mode);
        $values = match ($restoreMode) {
            RestoreMode::Folder => $this->folderValues(),
            RestoreMode::Profile => $this->profileValues(),
        };

        $queue($restoreMode->script(), $targetDevice, auth()->user(), [
            'SnapshotId' => $this->snapshotId,
            ...$values,
            'SourceDevice' => $this->device->id,
        ], attribute: 'restore');

        $this->showModal = false;
        $this->dispatch('command-queued');

        Flux::toast(text: "Restoring {$this->device->hostname}'s backup onto {$targetDevice->hostname}. Its result shows on that PC's Commands tab.", heading: $restoreMode->script()->queuedHeading(), variant: 'success');
    }

    /**
     * @return array<string, string>
     */
    private function folderValues(): array
    {
        $this->validate([
            'includePath' => ['nullable', 'string', 'max:500', 'regex:/^[A-Za-z]:\\\\/', 'not_regex:/[<>"|?*\x00-\x1f]/'],
            'target' => ['nullable', 'string', 'max:500', 'regex:/^[A-Za-z]:\\\\[^<>:"|?*\x00-\x1f]+$/'],
        ], $this->messages());

        return ['IncludePath' => $this->includePath, 'Target' => $this->target];
    }

    /**
     * The account goes by username: the script finds its SID on the PC.
     *
     * @return array<string, string>
     */
    private function profileValues(): array
    {
        $this->validate([
            'staffAccountId' => ['required', 'integer', Rule::in($this->staffAccounts->modelKeys())],
            'sourceProfile' => ['required', 'string', 'max:500', 'regex:/^[A-Za-z]:\\\\[^<>:"|?*\x00-\x1f]+$/'],
        ], $this->messages());

        return ['SourceProfile' => rtrim($this->sourceProfile, '\\'), 'Username' => $this->staffAccounts->find($this->staffAccountId)->username];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'snapshotId.in' => 'Choose a snapshot from the list. Refresh snapshots if it is missing.',
            'targetDeviceId.required' => 'Choose the PC to restore onto.',
            'targetDeviceId.in' => 'Choose a Windows PC that takes commands.',
            'includePath.regex' => 'Write the path as it was on the PC, for example C:\\Users\\anna\\Documents.',
            'includePath.not_regex' => 'The path contains characters Windows does not allow.',
            'target.regex' => 'Use a folder path on the PC, for example C:\\Restore\\Anna.',
            'staffAccountId.required' => 'Choose the staff account to restore into.',
            'staffAccountId.in' => 'Choose a staff account that targets the PC being restored onto.',
            'sourceProfile.required' => 'Choose or type the profile folder in the backup, for example C:\\Users\\SOPHIE.SMITH.',
            'sourceProfile.regex' => 'Write the profile folder as it was on the PC, for example C:\\Users\\SOPHIE.SMITH.',
        ];
    }

    /**
     * The staff accounts that target the chosen PC.
     *
     * @return Collection<int, StaffAccount>
     */
    #[Computed]
    public function staffAccounts(): Collection
    {
        $targetDevice = $this->targets->find($this->targetDeviceId);

        return $targetDevice === null
            ? new Collection
            : StaffAccount::query()->whereIn('id', $this->staffAccountQueries->accountIdsTargeting($targetDevice))->orderBy('username')->get();
    }

    /**
     * The profile folders the chosen snapshot holds, or the newest one's for latest.
     *
     * @return SupportCollection<int, string>
     */
    #[Computed]
    public function sourceProfiles(): SupportCollection
    {
        $snapshots = $this->device->backupSnapshots();
        $snapshot = $this->snapshotId === 'latest'
            ? $snapshots->latest('taken_at')->first()
            : $snapshots->where('snapshot_id', $this->snapshotId)->first();

        return collect($snapshot?->paths)->values();
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
        $isProfileMode = $this->showModal && $this->mode === RestoreMode::Profile->value;

        return view('livewire.devices.restore-backup', [
            'targets' => $this->showModal ? $this->targets : collect(),
            'modes' => RestoreMode::cases(),
            'isProfileMode' => $isProfileMode,
            'staffAccounts' => $isProfileMode ? $this->staffAccounts : collect(),
            'sourceProfiles' => $this->showModal ? $this->sourceProfiles : collect(),
            'defaultTarget' => 'C:\\Restore\\'.now()->format('Ymd-Hi'),
        ]);
    }
}
