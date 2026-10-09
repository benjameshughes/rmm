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
 * A running command reported how far it has got. Kept apart from
 * CommandUpdated on purpose: that one wakes the result listeners and the
 * fleet list, while this fires every few seconds and only the pages that
 * show progress listen. Scalars only, so the file being worked on never
 * reaches the queue or the socket.
 */
final class CommandProgressed implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    public readonly int $commandId;

    public readonly int $deviceId;

    public function __construct(DeviceCommand $command)
    {
        $this->commandId = $command->id;
        $this->deviceId = $command->device_id;
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('devices'),
            new PrivateChannel("devices.{$this->deviceId}"),
        ];
    }

    /** @return array{commandId: int, deviceId: int} */
    public function broadcastWith(): array
    {
        return [
            'commandId' => $this->commandId,
            'deviceId' => $this->deviceId,
        ];
    }
}
