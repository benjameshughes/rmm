<?php

declare(strict_types=1);

namespace App\Actions\Script;

use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\User;

final class ExecuteScriptOnDevice
{
    /**
     * @param  ScheduledTask|null  $scheduledTask  The schedule that queued this run, so its result can raise or resolve an alert
     */
    public function __invoke(Script $script, Device $device, User $user, ?ScheduledTask $scheduledTask = null): DeviceCommand
    {
        return DeviceCommand::create([
            'device_id' => $device->id,
            'script_id' => $script->id,
            'scheduled_task_id' => $scheduledTask?->id,
            'script_content' => $script->script_content,
            'script_type' => $script->script_type->value,
            'status' => CommandStatus::Pending,
            'queued_at' => now(),
            'queued_by' => $user->id,
            'timeout_seconds' => $script->timeout_seconds,
        ]);
    }
}
