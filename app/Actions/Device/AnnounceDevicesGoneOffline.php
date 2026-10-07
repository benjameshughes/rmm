<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Actions\Printer\PausePrinterWatch;
use App\Actions\VirtualPrinter\PauseVirtualPrinterWatch;
use App\Enums\DeviceStatus;
use App\Events\DeviceUpdated;
use App\Models\Device;

final class AnnounceDevicesGoneOffline
{
    public function __construct(
        private readonly PauseVirtualPrinterWatch $pauseVirtualPrinterWatch,
        private readonly PausePrinterWatch $pausePrinterWatch,
    ) {}

    /**
     * Online is worked out from last_seen, so a device going quiet changes no
     * row and fires no event. This announces devices that crossed the offline
     * threshold within the last window so open pages flip without a refresh.
     * The window is wider than the one-minute schedule so a late run still
     * catches them; a second announcement only re-renders the page. Devices
     * that announced they were powering off already broadcast at the time.
     * Offline time never counts towards a Virtual Printer being down or a printer problem.
     *
     * @return int How many devices were announced
     */
    public function __invoke(): int
    {
        $threshold = Device::onlineCutoff();

        return Device::query()
            ->where('status', DeviceStatus::Active)
            ->notPoweringOff()
            ->whereBetween('last_seen', [$threshold->copy()->subSeconds(config('devices.online.announce_window_seconds')), $threshold])
            ->get()
            ->each(function (Device $device): void {
                ($this->pauseVirtualPrinterWatch)($device);
                ($this->pausePrinterWatch)($device);
                DeviceUpdated::dispatch($device, true);
            })
            ->count();
    }
}
