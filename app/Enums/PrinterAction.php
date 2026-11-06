<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the Printers tab can do to a PC's print queues, each a system script.
 * InstallZebra sets up the label printer on the ZDesigner driver;
 * CaptureZebraTemplate saves one ZDesigner queue's settings for it to restore.
 * AddCupsPrinter and RemoveCupsPrinter manage printers shared by the CUPS server.
 */
enum PrinterAction: string
{
    case ClearQueue = 'clear-print-queue';
    case CancelJob = 'cancel-print-job';
    case RestartSpooler = 'restart-print-spooler';
    case PrintTestPage = 'print-test-page';
    case InstallZebra = 'install-zebra';
    case CaptureZebraTemplate = 'capture-zebra-template';
    case AddCupsPrinter = 'add-cups-printer';
    case RemoveCupsPrinter = 'remove-cups-printer';

    public function label(): string
    {
        return match ($this) {
            self::ClearQueue => 'Clear queue',
            self::CancelJob => 'Cancel job',
            self::RestartSpooler => 'Restart spooler',
            self::PrintTestPage => 'Print test page',
            self::InstallZebra => 'Install Zebra',
            self::CaptureZebraTemplate => 'Use as Zebra template',
            self::AddCupsPrinter => 'Install printer',
            self::RemoveCupsPrinter => 'Remove printer',
        };
    }

    public function queuedHeading(): string
    {
        return match ($this) {
            self::ClearQueue => 'Clearing the queue',
            self::CancelJob => 'Cancelling the job',
            self::RestartSpooler => 'Restarting the print spooler',
            self::PrintTestPage => 'Test page queued',
            self::InstallZebra => 'Installing the Zebra',
            self::CaptureZebraTemplate => 'Capturing the Zebra template',
            self::AddCupsPrinter => 'Installing the printer',
            self::RemoveCupsPrinter => 'Removing the printer',
        };
    }
}
