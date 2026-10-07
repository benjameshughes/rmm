<?php

declare(strict_types=1);

namespace App\Actions\Printer;

use App\Models\Device;

final class PausePrinterWatch
{
    public function __construct(private readonly SyncPrinterAlerts $syncPrinterAlerts) {}

    /**
     * A PC that went offline, is powering off or is powering on is not plainly
     * online, so how long its printers have been a problem starts again from
     * its next snapshot, and any open printer alert is resolved.
     */
    public function __invoke(Device $device): void
    {
        $device->problemPrinters()->update(['problem_since' => null]);
        $device->forceFill(['spooler_down_since' => null])->save();

        ($this->syncPrinterAlerts)($device);
    }
}
