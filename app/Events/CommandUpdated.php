<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\DeviceCommand;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Holds scalars only, so script content and output never reach the queue or the socket.
 */
final class CommandUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    public readonly int $commandId;

    public readonly int $deviceId;

    public readonly string $status;

    public function __construct(DeviceCommand $command)
    {
        $this->commandId = $command->id;
        $this->deviceId = $command->device_id;
        $this->status = $command->status->value;
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('devices'),
            new PrivateChannel("devices.{$this->deviceId}"),
        ];
    }

    /** @return array{commandId: int, deviceId: int, status: string} */
    public function broadcastWith(): array
    {
        return [
            'commandId' => $this->commandId,
            'deviceId' => $this->deviceId,
            'status' => $this->status,
        ];
    }
}
