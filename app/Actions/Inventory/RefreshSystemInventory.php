<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;

/**
 * Queues the system-inventory script, unless one is already waiting for the device.
 */
final class RefreshSystemInventory
{
    public function __construct(
        private readonly ExecuteScriptOnDevice $executeScript,
    ) {}

    public function __invoke(Device $device, User $user): ?DeviceCommand
    {
        if (! $device->hasSystemInventory() || $device->status !== DeviceStatus::Active) {
            return null;
        }

        $script = Script::findSystem(config('inventory.system_slug'));

        if ($device->pendingCommands()->where('script_id', $script->id)->exists()) {
            return null;
        }

        return ($this->executeScript)($script, $device, $user);
    }
}
