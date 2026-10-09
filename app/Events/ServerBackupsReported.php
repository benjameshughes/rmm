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
 * A server's agent reported its backup status files. Agents send them with
 * every metrics report, identical until a backup runs, so listeners run on
 * every report (an overdue job is noticed as soon as it is due) but the
 * browser only hears about it when a job actually changed. Holds scalars only.
 */
final class ServerBackupsReported implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $deviceId,
        public readonly bool $hasChanged,
    ) {}

    public function broadcastWhen(): bool
    {
        return $this->hasChanged;
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('devices'),
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
