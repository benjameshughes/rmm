<?php

declare(strict_types=1);

namespace App\Actions\Printer;

use App\Enums\PrinterAction;
use App\Exceptions\ZebraTemplateRefused;
use App\Models\DeviceCommand;
use App\Models\DevicePrinter;
use App\Models\User;

/**
 * Queues capture-zebra-template on a printer's PC. Only a queue on the
 * ZDesigner driver can be captured: its settings restore onto no other driver.
 */
final class QueueZebraTemplateCapture
{
    public function __construct(
        private readonly QueuePrinterAction $queuePrinterAction,
    ) {}

    public function __invoke(DevicePrinter $printer, User $user): DeviceCommand
    {
        throw_unless($printer->queue()->usesZDesignerDriver(), ZebraTemplateRefused::notZDesigner($printer));

        return ($this->queuePrinterAction)(PrinterAction::CaptureZebraTemplate, $printer->device, $user, printerName: $printer->name);
    }
}
