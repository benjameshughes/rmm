<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Enums\DevicePowerState;
use App\Enums\PowerEventReason;
use App\Models\Device;
use Illuminate\Support\Facades\Log;

final class RecordDevicePowerEvent
{
    /**
     * A device powering on is talking to us, so it counts as a check-in. One
     * powering off keeps its last_seen: it is going away, not reporting in.
     * Saving the device broadcasts DeviceUpdated, which flips open pages.
     */
    public function __invoke(Device $device, DevicePowerState $powerState, PowerEventReason $reason, ?string $ip = null): void
    {
        $device->forceFill([
            ...($powerState === DevicePowerState::PoweringOn ? $device->checkInAttributes($ip) : []),
            'power_state' => $powerState,
            'power_state_changed_at' => now(),
        ])->save();

        Log::info('device.power', [
            'device_id' => $device->id,
            'power_state' => $powerState->value,
            'reason' => $reason->value,
        ]);
    }
}
