<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Schedule\SyncScheduledScriptAlert;
use App\Enums\CommandStatus;
use App\Events\CommandUpdated;
use App\Models\DeviceCommand;

/**
 * CommandUpdated fires after commit on every command change, whichever path
 * finished it (agent result, stale-command expiry), so this is the one place
 * a scheduled script's outcome is seen.
 */
final class SyncScheduledScriptAlertForCommand
{
    public function __construct(
        private readonly SyncScheduledScriptAlert $syncScheduledScriptAlert,
    ) {}

    public function handle(CommandUpdated $event): void
    {
        if (! CommandStatus::from($event->status)->isTerminal()) {
            return;
        }

        $command = DeviceCommand::query()
            ->with(['device', 'scheduledTask'])
            ->whereNotNull('scheduled_task_id')
            ->find($event->commandId);

        if ($command === null) {
            return;
        }

        ($this->syncScheduledScriptAlert)($command);
    }
}
