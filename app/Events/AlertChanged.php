<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Holds scalars only, so alert messages (which embed hostnames and values) never reach the socket.
 */
final class AlertChanged implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    public readonly int $alertId;

    public readonly int $deviceId;

    public readonly string $status;

    private readonly bool $isCreatedOrStatusChanged;

    public function __construct(Alert $alert)
    {
        $this->alertId = $alert->id;
        $this->deviceId = $alert->device_id;
        $this->status = $alert->status->value;
        $this->isCreatedOrStatusChanged = ($alert->wasRecentlyCreated && ! $alert->wasChanged()) || $alert->wasChanged('status');
    }

    /**
     * Alerts only ever enter Triggered when they are created, so a status change into it means a fresh alert.
     */
    public function isNewlyTriggered(): bool
    {
        return $this->isCreatedOrStatusChanged && $this->status === AlertStatus::Triggered->value;
    }

    /**
     * An ongoing violation refreshes current_value on every metrics post; that is not news.
     */
    public function broadcastWhen(): bool
    {
        return $this->isCreatedOrStatusChanged;
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('devices')];
    }

    /** @return array{alertId: int, deviceId: int, status: string} */
    public function broadcastWith(): array
    {
        return [
            'alertId' => $this->alertId,
            'deviceId' => $this->deviceId,
            'status' => $this->status,
        ];
    }
}
