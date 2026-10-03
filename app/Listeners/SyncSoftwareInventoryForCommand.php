<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Software\SyncDeviceSoftware;
use App\Enums\CommandStatus;
use App\Events\CommandUpdated;
use App\Models\DeviceCommand;

/**
 * A successful winget-inventory run replaces the device's software inventory.
 */
final class SyncSoftwareInventoryForCommand
{
    public function __construct(
        private readonly SyncDeviceSoftware $syncDeviceSoftware,
    ) {}

    public function handle(CommandUpdated $event): void
    {
        if ($event->status !== CommandStatus::Completed->value) {
            return;
        }

        $command = DeviceCommand::query()
            ->with(['device', 'script'])
            ->whereRelation('script', 'slug', config('software.inventory_slug'))
            ->where('exit_code', 0)
            ->find($event->commandId);

        if ($command === null) {
            return;
        }

        ($this->syncDeviceSoftware)($command->device, (string) $command->output);
    }
}
