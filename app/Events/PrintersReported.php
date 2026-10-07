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
 * A device's agent posted a fresh printer snapshot. Holds scalars only. Sent
 * on the device's own channel only: snapshots arrive every minute from every
 * PC, and fleet pages hear about problems starting or clearing through DeviceUpdated.
 */
final class PrintersReported implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $deviceId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("devices.{$this->deviceId}"),
        ];
    }

    /** @return array{deviceId: int} */
    public function broadcastWith(): array
    {
        return [
            'deviceId' => $this->deviceId,
        ];
    }
}
