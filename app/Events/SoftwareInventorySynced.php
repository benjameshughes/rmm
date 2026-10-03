<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A device's software inventory was replaced. Holds scalars only.
 */
final class SoftwareInventorySynced implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $deviceId,
        public readonly int $packageCount,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('devices'),
            new PrivateChannel("devices.{$this->deviceId}"),
        ];
    }

    /** @return array{deviceId: int, packageCount: int} */
    public function broadcastWith(): array
    {
        return [
            'deviceId' => $this->deviceId,
            'packageCount' => $this->packageCount,
        ];
    }
}
