<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DeletePath\QueueQuarantinePurge;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class PurgeQuarantines extends Command
{
    protected $signature = 'quarantine:purge';

    protected $description = 'Queue purge-quarantine on every Windows PC holding quarantined files past their purge date';

    public function handle(QueueQuarantinePurge $queuePurge): int
    {
        $automation = User::automation();

        $queued = Device::query()
            ->where('status', DeviceStatus::Active)
            ->acceptsCommands()
            ->runsWindows()
            ->whereHas('quarantines', fn (Builder $quarantineQuery): Builder => $quarantineQuery->due())
            ->get()
            ->map(fn (Device $device) => $queuePurge($device, $automation))
            ->filter();

        $this->components->info("Queued a quarantine purge on {$queued->count()} devices.");

        return self::SUCCESS;
    }
}
