<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Enums\DevicePowerState;
use App\Models\Device;

final class SettleExpiredPowerOn
{
    /**
     * "Powering on" is held briefly so the badge is seen, then a check-in
     * normally clears it. A PC that checks in once and dozes off never sends
     * that check-in, so clear it here once the hold has passed; the update
     * broadcasts DeviceUpdated so open pages settle without a refresh.
     *
     * @return int How many devices were settled
     */
    public function __invoke(): int
    {
        return Device::query()
            ->where('power_state', DevicePowerState::PoweringOn)
            ->where('power_state_changed_at', '<=', now()->subSeconds(config('devices.power.powering_on_hold_seconds')))
            ->get()
            ->each(fn (Device $device) => $device->forceFill(['power_state' => null, 'power_state_changed_at' => null])->save())
            ->count();
    }
}
