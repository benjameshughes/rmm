<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;

final class SettleExpiredPowerStates
{
    /**
     * "Powering on" is held briefly so the badge is seen, then a check-in
     * normally clears it; a PC that checks in once and dozes off never sends
     * that check-in. "Powering off" normally ends with a wake, but one that
     * never comes (a crash, a dead PSU) must fall back to Offline so offline
     * alerting resumes. Both are cleared here once their window has passed;
     * the update broadcasts DeviceUpdated so open pages settle without a refresh.
     *
     * @return int How many devices were settled
     */
    public function __invoke(): int
    {
        return Device::query()
            ->withLapsedPowerState()
            ->get()
            ->each(fn (Device $device) => $device->forceFill(['power_state' => null, 'power_state_changed_at' => null])->save())
            ->count();
    }
}
