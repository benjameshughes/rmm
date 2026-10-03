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
 *
 * A routine metrics report is broadcast so open pages see fresh numbers, but it only
 * counts as a state change when it moved something worth showing at once (back online,
 * a new power state). Busy lists throttle the rest.
 */
final class DeviceUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    public readonly int $deviceId;

    public readonly string $status;

    public readonly bool $isStateChange;

    private readonly bool $isWorthBroadcasting;

    public function __construct(Device $device, bool $alwaysBroadcast = false, bool $isRoutineReport = false)
    {
        $hasStateChanged = $device->wasChanged(config('devices.broadcast.attributes')) || $this->cameBackOnline($device);

        $this->deviceId = $device->id;
        $this->status = $device->status->value;
        $this->isStateChange = ! $isRoutineReport || $hasStateChanged;
        $this->isWorthBroadcasting = $alwaysBroadcast || $hasStateChanged;
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

    /** @return array{deviceId: int, status: string, isStateChange: bool} */
    public function broadcastWith(): array
    {
        return [
            'deviceId' => $this->deviceId,
            'status' => $this->status,
            'isStateChange' => $this->isStateChange,
        ];
    }
}
