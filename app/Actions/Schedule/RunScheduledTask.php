<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Actions\Device\BulkExecuteScript;
use App\Actions\Device\WakeDevice;
use App\Enums\ScheduledTaskAction;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ScheduledTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class RunScheduledTask
{
    public function __construct(
        private BulkExecuteScript $bulkExecuteScript,
        private WakeDevice $wakeDevice,
    ) {}

    /**
     * @return int How many devices the task ran on
     */
    public function __invoke(ScheduledTask $task): int
    {
        if (! $task->is_active || ($task->action->requiresScript() && ($task->script === null || $task->script->is_internal))) {
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
        return ($this->bulkExecuteScript)($task->script, $this->devicesWithoutQueuedRun($task), $task->createdBy, $task->parameters ?? [], $task);
    }

    /**
     * Offline devices never collect their commands, so without this every run of the schedule
     * stacks another copy of the same script on them. A device that still has this script with
     * the same parameters pending, sent or running is skipped until that run finishes.
     *
     * @return Collection<int, Device>
     */
    private function devicesWithoutQueuedRun(ScheduledTask $task): Collection
    {
        $devices = $task->resolveDevices();
        $parameters = $task->parameters ?: null;

        $alreadyQueued = DeviceCommand::query()
            ->inFlight()
            ->where('script_id', $task->script_id)
            ->whereIn('device_id', $devices->pluck('id'))
            ->get(['device_id', 'parameters'])
            ->filter(fn (DeviceCommand $command): bool => ($command->parameters ?: null) == $parameters)
            ->pluck('device_id');

        $alreadyQueued->each(fn (int $deviceId) => Log::info('schedule.skipped_already_queued', [
            'scheduled_task_id' => $task->id,
            'device_id' => $deviceId,
        ]));

        return $devices->reject(fn (Device $device): bool => $alreadyQueued->contains($device->id));
    }

    /**
     * Online devices are already awake and devices without a MAC cannot be woken, so both are skipped,
     * as are monitor-only devices, which are never sent a power signal.
     */
    private function wake(ScheduledTask $task): int
    {
        [$monitorOnly, $wakeable] = $task->resolveDevices()
            ->filter(fn (Device $device): bool => $device->isWakeable)
            ->partition(fn (Device $device): bool => $device->isMonitorOnly);

        $monitorOnly->each(fn (Device $device) => Log::info('wake.skipped_monitor_only', [
            'scheduled_task_id' => $task->id,
            'device_id' => $device->id,
        ]));

        return $wakeable
            ->each(fn (Device $device) => ($this->wakeDevice)($device))
            ->count();
    }
}
