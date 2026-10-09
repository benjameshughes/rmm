<?php

declare(strict_types=1);

namespace App\Actions\Script;

use App\DTOs\CommandProgress;
use App\Enums\CommandStatus;
use App\Events\CommandProgressed;
use App\Models\DeviceCommand;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps the newest progress a running command reported. The checks ride in
 * the UPDATE itself, so a report that arrives late (older than the one
 * stored) or after the command finished changes nothing. A query update
 * skips model events on purpose: progress is not a change worth auditing or
 * waking the result listeners for, so only CommandProgressed is announced.
 */
final class RecordCommandProgress
{
    public function __invoke(DeviceCommand $command, CommandProgress $progress, CarbonInterface $at): bool
    {
        $recorded = DeviceCommand::query()
            ->whereKey($command->getKey())
            ->whereIn('status', CommandStatus::withAgent())
            ->where(fn (Builder $query): Builder => $query->whereNull('progress_at')->orWhere('progress_at', '<', $at))
            ->update([
                'progress' => json_encode($progress->toArray()),
                'progress_at' => $at,
            ]) === 1;

        if ($recorded) {
            CommandProgressed::dispatch($command);
        }

        return $recorded;
    }
}
