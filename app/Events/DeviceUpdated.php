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
use Illuminate\Support\Carbon;

/**
 * Holds scalars only, so neither the queued job nor the payload can carry device secrets.
 *
 * Every heartbeat saves the device (last_seen), and broadcasting each one made every
 * open page re-render several times a second across a fleet. Saves only broadcast when
 * something worth showing changed, or the device came back online; deliberate
 * announcements (new metrics, tags, key claimed, gone offline) pass $alwaysBroadcast,
 * positionally: Dispatchable::dispatch() drops named arguments.
 */
final class DeviceUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    public readonly int $deviceId;

    public readonly string $status;

    private readonly bool $isWorthBroadcasting;

    public function __construct(Device $device, bool $alwaysBroadcast = false)
    {
        $this->deviceId = $device->id;
        $this->status = $device->status->value;
        $this->isWorthBroadcasting = $alwaysBroadcast
            || $device->wasChanged(config('devices.broadcast.attributes'))
            || $this->cameBackOnline($device);
    }

    public function broadcastWhen(): bool
    {
        return $this->isWorthBroadcasting;
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('devices'),
            new PrivateChannel("devices.{$this->deviceId}"),
        ];
    }

    /**
     * A check-in from a device that was offline flips it to Online on every open page.
     */
    private function cameBackOnline(Device $device): bool
    {
        $previousLastSeen = $device->getPrevious()['last_seen'] ?? null;

        return $device->wasChanged('last_seen')
            && ($previousLastSeen === null || Carbon::parse($previousLastSeen)->lessThanOrEqualTo(Device::onlineCutoff()));
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
