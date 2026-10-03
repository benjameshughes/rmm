<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Software\RefreshSoftwareInventory;
use App\Enums\CommandStatus;
use App\Events\CommandUpdated;
use App\Models\DeviceCommand;
use Illuminate\Database\Eloquent\Builder;

/**
 * Once an install, upgrade or uninstall has finished, whatever its result, a fresh
 * inventory is queued so the software pages show what is really installed. The
 * inventory script is not one of those, so it never queues itself.
 */
final class QueueSoftwareInventoryAfterChange
{
    public function __construct(
        private readonly RefreshSoftwareInventory $refreshSoftwareInventory,
    ) {}

    public function handle(CommandUpdated $event): void
    {
        $status = CommandStatus::from($event->status);

        if (! $status->isTerminal() || $status === CommandStatus::Cancelled) {
            return;
        }

        $command = DeviceCommand::query()
            ->with(['device', 'queuedBy'])
            ->whereHas('script', fn (Builder $query): Builder => $query->whereIn('slug', config('software.refresh_after_slugs')))
            ->find($event->commandId);

        if ($command === null || $command->queuedBy === null) {
            return;
        }

        ($this->refreshSoftwareInventory)($command->device, $command->queuedBy);
    }
}
