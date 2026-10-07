<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the Printers tab can do to a PC's print queues, each a system script.
 */
enum PrinterAction: string
{
    case ClearQueue = 'clear-print-queue';
    case CancelJob = 'cancel-print-job';
    case RestartSpooler = 'restart-print-spooler';
    case PrintTestPage = 'print-test-page';

    public function label(): string
    {
        return match ($this) {
            self::ClearQueue => 'Clear queue',
            self::CancelJob => 'Cancel job',
            self::RestartSpooler => 'Restart spooler',
            self::PrintTestPage => 'Print test page',
        };
    }

    public function queuedHeading(): string
    {
        return match ($this) {
            self::ClearQueue => 'Clearing the queue',
            self::CancelJob => 'Cancelling the job',
            self::RestartSpooler => 'Restarting the print spooler',
            self::PrintTestPage => 'Test page queued',
        };
    }
}
