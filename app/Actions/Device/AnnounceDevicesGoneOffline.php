<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Enums\DeviceStatus;
use App\Events\DeviceUpdated;
use App\Models\Device;

final class AnnounceDevicesGoneOffline
{
    /**
     * Online is worked out from last_seen, so a device going quiet changes no
     * row and fires no event. This announces devices that crossed the offline
     * threshold within the last window so open pages flip without a refresh.
     * The window is wider than the one-minute schedule so a late run still
     * catches them; a second announcement only re-renders the page.
     *
     * @return int How many devices were announced
     */
    public function __invoke(): int
    {
        $threshold = now()->subMinutes(config('devices.online.threshold_minutes'));

        return Device::query()
            ->where('status', DeviceStatus::Active)
            ->whereBetween('last_seen', [$threshold->copy()->subSeconds(config('devices.online.announce_window_seconds')), $threshold])
            ->get()
            ->each(fn (Device $device) => DeviceUpdated::dispatch($device))
            ->count();
    }
}
