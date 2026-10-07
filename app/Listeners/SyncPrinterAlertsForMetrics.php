<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Printer\SyncPrinterAlerts;
use App\Events\MetricsReceived;

/**
 * Printer snapshots raise and resolve printer alerts as they arrive. This
 * catches the PC whose printer reports stopped while it stayed online, so an
 * alert never outlives the data behind it.
 */
final class SyncPrinterAlertsForMetrics
{
    public function __construct(private readonly SyncPrinterAlerts $syncPrinterAlerts) {}

    public function handle(MetricsReceived $event): void
    {
        ($this->syncPrinterAlerts)($event->device);
    }
}
