<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Netdata\SyncNetdataRepairAlert;
use App\Enums\CommandStatus;
use App\Events\CommandUpdated;
use App\Models\DeviceCommand;
use Illuminate\Database\Eloquent\Builder;

/**
 * A finished Netdata repair raises or resolves the device's repair alert,
 * whichever path finished it (agent result, stale-command expiry).
 */
final class SyncNetdataRepairAlertForCommand
{
    public function __construct(
        private readonly SyncNetdataRepairAlert $syncNetdataRepairAlert,
    ) {}

    public function handle(CommandUpdated $event): void
    {
        if (! CommandStatus::from($event->status)->isTerminal()) {
            return;
        }

        $repair = DeviceCommand::query()
            ->with('device')
            ->whereHas('script', fn (Builder $query): Builder => $query->system()->where('slug', config('devices.metrics.netdata_repair_script_slug')))
            ->find($event->commandId);

        if ($repair === null) {
            return;
        }

        ($this->syncNetdataRepairAlert)($repair);
    }
}
