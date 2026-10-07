<?php

declare(strict_types=1);

namespace App\Actions\VirtualPrinter;

use App\Models\Device;

final class PauseVirtualPrinterWatch
{
    public function __construct(private readonly SyncVirtualPrinterAlert $syncVirtualPrinterAlert) {}

    /**
     * A PC that went offline, is powering off or is powering on is not plainly
     * online, so the time its Virtual Printer has been missing starts again from
     * its next report and any open alert is resolved.
     */
    public function __invoke(Device $device): void
    {
        $device->forceFill(['virtual_printer_missing_since' => null])->save();

        ($this->syncVirtualPrinterAlert)($device);
    }
}
