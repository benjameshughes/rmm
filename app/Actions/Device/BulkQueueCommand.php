<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Collection;

final class BulkQueueCommand
{
    public function __construct(
        private QueueDeviceCommand $queueCommand,
    ) {}

    /** @param Collection<int, Device> $devices */
    public function __invoke(Collection $devices, string $scriptContent, string $scriptType, User $user, int $timeout = 300): int
    {
        return $devices
            ->filter(fn (Device $device): bool => $device->status === DeviceStatus::Active)
            ->each(fn (Device $device) => ($this->queueCommand)($device, $scriptContent, $scriptType, $user, $timeout))
            ->count();
    }
}
