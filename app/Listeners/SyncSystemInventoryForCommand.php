<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Inventory\SyncDeviceInventory;
use App\Enums\CommandStatus;
use App\Events\CommandUpdated;
use App\Models\DeviceCommand;

/**
 * A successful system-inventory run stores a new snapshot for the device.
 */
final class SyncSystemInventoryForCommand
{
    public function __construct(
        private readonly SyncDeviceInventory $syncDeviceInventory,
    ) {}

    public function handle(CommandUpdated $event): void
    {
        if ($event->status !== CommandStatus::Completed->value) {
            return;
        }

        $command = DeviceCommand::query()
            ->with(['device', 'script'])
            ->whereRelation('script', 'slug', config('inventory.system_slug'))
            ->where('exit_code', 0)
            ->find($event->commandId);

        if ($command === null) {
            return;
        }

        ($this->syncDeviceInventory)($command->device, (string) $command->output);
    }
}
