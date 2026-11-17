<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Backup\QueueBackupScript;
use App\DTOs\CommandProgress;
use App\Enums\BackupScript;
use App\Enums\BackupState;
use App\Enums\DeviceTab;
use App\Enums\ScriptPlatform;
use App\Models\Device;
use App\Models\DeviceBackup;
use App\Models\DeviceBackupSnapshot;
use App\Models\DeviceCommand;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A PC's file backups: where they stand, recent runs, the snapshots on the
 * backup server with a restore for each, and its backup credentials.
 */
#[Layout('components.layouts.app')]
#[Lazy]
final class Backups extends Component
{
    public Device $device;

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
    }

    #[On('echo-private:devices.{device.id},DeviceUpdated')]
    #[On('backup-credentials-saved')]
    public function refreshDevice(): void
    {
        $this->device->refresh();
    }

    #[On('echo-private:devices.{device.id},CommandUpdated')]
    #[On('echo-private:devices.{device.id},CommandProgressed')]
    #[On('command-queued')]
    public function refreshCommands(): void
    {
        $this->renderIsland(name: 'backups');
        $this->skipRender();
    }

    public function backUpNow(QueueBackupScript $queue): void
    {
        $this->queue(BackupScript::BackUp, $queue, ['StaggerMinutes' => 0], 'A backup');
    }

    public function refreshSnapshots(QueueBackupScript $queue): void
    {
        $this->queue(BackupScript::ListSnapshots, $queue, [], 'The snapshot list');
    }

    /**
     * @param  array<string, int>  $values
     */
    private function queue(BackupScript $backupScript, QueueBackupScript $queue, array $values, string $subject): void
    {
        $this->authorize('runCommands', $this->device);
        abort_unless($this->device->canBackUp(), 422);

        $queue($backupScript, $this->device, auth()->user(), $values);
        $this->dispatch('command-queued');

        Flux::toast(text: "{$subject} for {$this->device->hostname} is queued. This tab updates by itself once the agent has run it.", heading: $backupScript->queuedHeading(), variant: 'success');
    }

    public function placeholder(): View
    {
        return view('livewire.devices.placeholders.backups');
    }

    public function render(): View
    {
        return view('livewire.devices.backups')->title(DeviceTab::Backups->pageTitle($this->device));
    }

    #[Computed]
    public function isWindows(): bool
    {
        return $this->device->platform() === ScriptPlatform::Windows;
    }

    #[Computed]
    public function state(): BackupState
    {
        return $this->device->backupState();
    }

    #[Computed]
    public function problem(): ?string
    {
        return $this->device->backupProblem();
    }

    /** @return Collection<int, DeviceBackup> */
    #[Computed]
    public function backups(): Collection
    {
        return $this->isWindows ? $this->device->backups()->latest('finished_at')->latest('id')->limit(config('backup.history_shown'))->get() : collect();
    }

    /** @return Collection<int, DeviceBackupSnapshot> */
    #[Computed]
    public function snapshots(): Collection
    {
        return $this->isWindows ? $this->device->backupSnapshots()->latest('taken_at')->get() : collect();
    }

    /** @return Collection<string, DeviceCommand> */
    #[Computed]
    public function inFlight(): Collection
    {
        return $this->isWindows ? $this->inFlightBackupCommands() : collect();
    }

    #[Computed]
    public function isBackingUp(): bool
    {
        return (bool) $this->runningBackup()?->status->isWithAgent();
    }

    #[Computed]
    public function backupProgress(): ?CommandProgress
    {
        return $this->runningBackup()?->liveProgress();
    }

    private function runningBackup(): ?DeviceCommand
    {
        return $this->inFlight->get(BackupScript::BackUp->value);
    }

    /**
     * The newest in-flight run of each backup script, keyed by slug.
     *
     * @return Collection<string, DeviceCommand>
     */
    private function inFlightBackupCommands(): Collection
    {
        return $this->device->inFlightCommands()
            ->with('script')
            ->whereRelation('script', fn (Builder $scriptQuery): Builder => $scriptQuery->system()->whereIn('slug', collect(BackupScript::cases())->map(fn (BackupScript $script): string => $script->value)))
            ->oldest('id')
            ->get()
            ->keyBy(fn (DeviceCommand $command): string => $command->script->slug);
    }
}
