<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Holds scalars only, so neither the queued job nor the payload can carry device secrets.
 */
final class DeviceUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    public readonly int $deviceId;

    public readonly string $status;

    public function __construct(Device $device)
    {
        $this->deviceId = $device->id;
        $this->status = $device->status->value;
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('devices'),
            new PrivateChannel("devices.{$this->deviceId}"),
        ];
    }

    /** @return array{deviceId: int, status: string} */
    public function broadcastWith(): array
    {
        return [
            'deviceId' => $this->deviceId,
            'status' => $this->status,
        ];
    }
}
