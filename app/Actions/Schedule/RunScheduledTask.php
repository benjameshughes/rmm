<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Models\Device;
use App\Models\ScheduledTask;

final class RunScheduledTask
{
    public function __construct(
        private ExecuteScriptOnDevice $executeScript,
    ) {}

    public function __invoke(ScheduledTask $task): int
    {
        if (! $task->is_active || $task->script === null) {
            return 0;
        }

        $devices = $task->resolveDevices();
        $user = $task->createdBy;

        $count = $devices
            ->each(fn (Device $device) => ($this->executeScript)($task->script, $device, $user))
            ->count();

        $task->update(['last_run_at' => now()]);
        $task->calculateNextRun();

        return $count;
    }
}
