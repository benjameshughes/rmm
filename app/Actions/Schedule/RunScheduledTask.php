<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Actions\Device\WakeDevice;
use App\Actions\Script\ExecuteScriptOnDevice;
use App\Enums\ScheduledTaskAction;
use App\Models\Device;
use App\Models\ScheduledTask;

final class RunScheduledTask
{
    public function __construct(
        private ExecuteScriptOnDevice $executeScript,
        private WakeDevice $wakeDevice,
    ) {}

    /**
     * @return int How many devices the task ran on
     */
    public function __invoke(ScheduledTask $task): int
    {
        if (! $task->is_active || ($task->action->requiresScript() && $task->script === null)) {
            return 0;
        }

        $count = match ($task->action) {
            ScheduledTaskAction::RunScript => $this->runScript($task),
            ScheduledTaskAction::Wake => $this->wake($task),
        };

        $task->update(['last_run_at' => now()]);
        $task->calculateNextRun();

        return $count;
    }

    private function runScript(ScheduledTask $task): int
    {
        return $task->resolveDevices()
            ->each(fn (Device $device) => ($this->executeScript)($task->script, $device, $task->createdBy, $task))
            ->count();
    }

    /**
     * Online devices are already awake and devices without a MAC cannot be woken, so both are skipped.
     */
    private function wake(ScheduledTask $task): int
    {
        return $task->resolveDevices()
            ->filter(fn (Device $device): bool => $device->isWakeable)
            ->each(fn (Device $device) => ($this->wakeDevice)($device))
            ->count();
    }
}
