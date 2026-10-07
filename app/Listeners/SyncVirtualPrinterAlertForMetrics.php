<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\VirtualPrinter\SyncVirtualPrinterAlert;
use App\Events\MetricsReceived;

final class SyncVirtualPrinterAlertForMetrics
{
    public function __construct(private readonly SyncVirtualPrinterAlert $syncVirtualPrinterAlert) {}

    public function handle(MetricsReceived $event): void
    {
        ($this->syncVirtualPrinterAlert)($event->device);
    }
}
