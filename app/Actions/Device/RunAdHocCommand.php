<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Enums\CommandStatus;
use App\Enums\ScriptType;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;

final class RunAdHocCommand
{
    /**
     * Queue a typed command with no script behind it. The audit log records its
     * full text on creation, since there is no script to point back to.
     */
    public function __invoke(Device $device, User $user, string $commandText, ScriptType $type, int $timeoutSeconds): DeviceCommand
    {
        return DeviceCommand::create([
            'device_id' => $device->id,
            'script_id' => null,
            'script_content' => $commandText,
            'script_type' => $type->value,
            'status' => CommandStatus::Pending,
            'queued_at' => now(),
            'queued_by' => $user->id,
            'timeout_seconds' => $timeoutSeconds,
        ]);
    }
}
