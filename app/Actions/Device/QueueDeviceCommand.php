<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;

final class QueueDeviceCommand
{
    public function __invoke(Device $device, string $scriptContent, string $scriptType, User $user, int $timeout = 300): DeviceCommand
    {
        return DeviceCommand::create([
            'device_id' => $device->id,
            'script_content' => $scriptContent,
            'script_type' => $scriptType,
            'status' => CommandStatus::Pending,
            'queued_at' => now(),
            'queued_by' => $user->id,
            'timeout_seconds' => $timeout,
        ]);
    }
}
