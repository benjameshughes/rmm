<?php

declare(strict_types=1);

namespace App\Actions\Script;

use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;

final class ExecuteScriptOnDevice
{
    public function __invoke(Script $script, Device $device, User $user): DeviceCommand
    {
        return DeviceCommand::create([
            'device_id' => $device->id,
            'script_id' => $script->id,
            'script_content' => $script->script_content,
            'script_type' => $script->script_type->value,
            'status' => CommandStatus::Pending,
            'queued_at' => now(),
            'queued_by' => $user->id,
            'timeout_seconds' => $script->timeout_seconds,
        ]);
    }
}
