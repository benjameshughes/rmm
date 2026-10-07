<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Backup\RecordBackupRun;
use App\Actions\Backup\StoreBackupSnapshots;
use App\Enums\BackupScript;
use App\Enums\CommandStatus;
use App\Events\CommandUpdated;
use App\Models\DeviceCommand;
use Illuminate\Database\Eloquent\Builder;

/**
 * A finished backup-files run is recorded, failures and timeouts included,
 * and a successful backup-snapshots run replaces the device's snapshot list.
 * A cancelled command never ran, so it records nothing.
 */
final class RecordBackupResultForCommand
{
    public function __construct(
        private readonly RecordBackupRun $recordBackupRun,
        private readonly StoreBackupSnapshots $storeBackupSnapshots,
    ) {}

    public function handle(CommandUpdated $event): void
    {
        $status = CommandStatus::from($event->status);

        if (! $status->isTerminal() || $status === CommandStatus::Cancelled) {
            return;
        }

        $command = DeviceCommand::query()
            ->with(['device', 'script'])
            ->whereRelation('script', fn (Builder $scriptQuery): Builder => $scriptQuery->system()->whereIn('slug', [BackupScript::BackUp->value, BackupScript::ListSnapshots->value]))
            ->find($event->commandId);

        match (BackupScript::tryFrom((string) $command?->script->slug)) {
            BackupScript::BackUp => ($this->recordBackupRun)($command),
            BackupScript::ListSnapshots => $status === CommandStatus::Completed ? ($this->storeBackupSnapshots)($command) : null,
            default => null,
        };
    }
}
