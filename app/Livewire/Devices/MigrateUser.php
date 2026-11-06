<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\UserState\QueueUserStateScript;
use App\Enums\DeviceStatus;
use App\Enums\MigrationMode;
use App\Models\Device;
use App\Models\StaffAccount;
use App\Queries\AgentVersionQueries;
use App\Queries\StaffAccountQueries;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Migrates a user's documents and settings into a staff account on this PC
 * with USMT. From another PC it queues capture-user-state on the old PC,
 * and QueueUserStateApplyForCommand queues apply-user-state here once that
 * succeeds. On this PC it queues migrate-user-in-place. Sits beside the
 * restic profile restore, which it never touches.
 */
final class MigrateUser extends Component
{
    public Device $device;

    public bool $showModal = false;

    public string $mode = MigrationMode::FromAnotherPc->value;

    public ?int $sourceDeviceId = null;

    public string $fromUser = '';

    public ?int $staffAccountId = null;

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

    #[On('migrate-user')]
    public function open(): void
    {
        $this->resetValidation();
        $this->reset('mode', 'sourceDeviceId', 'fromUser', 'staffAccountId');
        $this->showModal = true;
    }

    public function migrate(QueueUserStateScript $queue, AgentVersionQueries $agentVersions): void
    {
        $this->authorize('runCommands', $this->device);

        $this->validate([
            'mode' => ['required', Rule::enum(MigrationMode::class)],
            'fromUser' => ['required', 'string', 'max:36', 'regex:/^([A-Za-z0-9][A-Za-z0-9._-]{0,14}\\\\)?[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/'],
            'staffAccountId' => ['required', 'integer', Rule::in($this->staffAccounts->modelKeys())],
        ], $this->messages());

        $migrationMode = MigrationMode::from($this->mode);
        $username = $this->staffAccounts->find($this->staffAccountId)->username;

        match ($migrationMode) {
            MigrationMode::FromAnotherPc => $this->queueCapture($queue, $agentVersions, $username),
            MigrationMode::OnThisPc => $queue($migrationMode->script(), $this->device, auth()->user(), [
                'FromUser' => $this->fromUser,
                'Username' => $username,
            ]),
        };

        $this->showModal = false;
        $this->dispatch('command-queued');

        $text = $migrationMode === MigrationMode::FromAnotherPc
            ? "Capturing {$this->fromUser} on {$this->sources->find($this->sourceDeviceId)->hostname}. Once that succeeds it loads into {$username} here."
            : "Migrating {$this->fromUser} into {$username}. Its result shows on the Commands tab.";

        Flux::toast(text: $text, heading: $migrationMode->script()->queuedHeading(), variant: 'success');
    }

    /**
     * The apply runs here later, queued by the RMM rather than this form, so
     * this PC's agent is checked now instead of failing after the capture.
     */
    private function queueCapture(QueueUserStateScript $queue, AgentVersionQueries $agentVersions, string $username): void
    {
        $this->validate([
            'sourceDeviceId' => ['required', 'integer', Rule::in($this->sources->modelKeys())],
        ], $this->messages());

        $source = $this->sources->find($this->sourceDeviceId);
        $this->authorize('runCommands', $source);

        throw_if(
            $this->device->isMonitorOnly || ! $agentVersions->supportsScriptParameters($this->device),
            ValidationException::withMessages(['migrate' => "{$this->device->hostname} cannot take the migration yet. Update its agent first."]),
        );

        $queue(MigrationMode::FromAnotherPc->script(), $source, auth()->user(), [
            'FromUser' => $this->fromUser,
            'ApplyDevice' => $this->device->id,
            'ApplyUsername' => $username,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'sourceDeviceId.required' => 'Choose the PC to migrate the user from.',
            'sourceDeviceId.in' => 'Choose a Windows PC with backups enabled that takes commands.',
            'fromUser.required' => 'Type the user to migrate, for example OLDDOMAIN\\michelle or michelle.',
            'fromUser.regex' => 'Write the user as DOMAIN\\name or a local username, for example OLDDOMAIN\\michelle.',
            'fromUser.max' => 'That user name is too long for Windows.',
            'staffAccountId.required' => 'Choose the staff account to migrate into.',
            'staffAccountId.in' => 'Choose a staff account that targets this PC.',
        ];
    }

    /**
     * The staff accounts that target this PC.
     *
     * @return Collection<int, StaffAccount>
     */
    #[Computed]
    public function staffAccounts(): Collection
    {
        return StaffAccount::query()->whereIn('id', $this->staffAccountQueries->accountIdsTargeting($this->device))->orderBy('username')->get();
    }

    /**
     * Other Windows PCs that take commands and have backups enabled, since
     * the capture travels through the old PC's repository.
     *
     * @return Collection<int, Device>
     */
    #[Computed]
    public function sources(): Collection
    {
        return Device::query()
            ->where('status', DeviceStatus::Active)
            ->runsWindows()
            ->acceptsCommands()
            ->whereKeyNot($this->device->id)
            ->orderBy('hostname')
            ->get()
            ->filter(fn (Device $source): bool => $source->canBackUp())
            ->values();
    }

    public function render(): View
    {
        $isFromAnotherPc = $this->showModal && $this->mode === MigrationMode::FromAnotherPc->value;

        return view('livewire.devices.migrate-user', [
            'modes' => MigrationMode::cases(),
            'isFromAnotherPc' => $isFromAnotherPc,
            'sources' => $isFromAnotherPc ? $this->sources : collect(),
            'staffAccounts' => $this->showModal ? $this->staffAccounts : collect(),
        ]);
    }
}
