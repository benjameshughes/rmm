<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Script;
use App\Models\User;
use Illuminate\Support\Collection;

final class BulkExecuteScript
{
    public function __construct(
        private ExecuteScriptOnDevice $executeScript,
    ) {}

    /** @param Collection<int, Device> $devices */
    public function __invoke(Script $script, Collection $devices, User $user): int
    {
        return $devices
            ->filter(fn (Device $device): bool => $device->status === DeviceStatus::Active)
            ->each(fn (Device $device) => ($this->executeScript)($script, $device, $user))
            ->count();
    }
}
