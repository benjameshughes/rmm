<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Collection;

/**
 * The PRINTER_STATUS_* bits from winspool.h that a Windows print queue
 * reports, so a raw status number reads as labelled states.
 */
enum PrinterStatusFlag: int
{
    case Paused = 0x1;
    case Error = 0x2;
    case PendingDeletion = 0x4;
    case PaperJam = 0x8;
    case PaperOut = 0x10;
    case ManualFeed = 0x20;
    case PaperProblem = 0x40;
    case Offline = 0x80;
    case IoActive = 0x100;
    case Busy = 0x200;
    case Printing = 0x400;
    case OutputBinFull = 0x800;
    case NotAvailable = 0x1000;
    case Waiting = 0x2000;
    case Processing = 0x4000;
    case Initializing = 0x8000;
    case WarmingUp = 0x10000;
    case TonerLow = 0x20000;
    case NoToner = 0x40000;
    case PagePunt = 0x80000;
    case UserIntervention = 0x100000;
    case OutOfMemory = 0x200000;
    case DoorOpen = 0x400000;
    case ServerUnknown = 0x800000;
    case PowerSave = 0x1000000;

    /**
     * The known flags set in a raw status, lowest bit first. Unknown bits are ignored.
     *
     * @return Collection<int, self>
     */
    public static function fromBits(int $bits): Collection
    {
        return collect(self::cases())
            ->filter(fn (self $flag): bool => ($bits & $flag->value) === $flag->value)
            ->values();
    }

    public function label(): string
    {
        return match ($this) {
            self::Paused => 'Paused',
            self::Error => 'Error',
            self::PendingDeletion => 'Being deleted',
            self::PaperJam => 'Paper jam',
            self::PaperOut => 'Out of paper',
            self::ManualFeed => 'Manual feed',
            self::PaperProblem => 'Paper problem',
            self::Offline => 'Offline',
            self::IoActive => 'Active',
            self::Busy => 'Busy',
            self::Printing => 'Printing',
            self::OutputBinFull => 'Output bin full',
            self::NotAvailable => 'Not available',
            self::Waiting => 'Waiting',
            self::Processing => 'Processing',
            self::Initializing => 'Initialising',
            self::WarmingUp => 'Warming up',
            self::TonerLow => 'Toner low',
            self::NoToner => 'Out of toner',
            self::PagePunt => 'Cannot print page',
            self::UserIntervention => 'Needs attention',
            self::OutOfMemory => 'Out of memory',
            self::DoorOpen => 'Door open',
            self::ServerUnknown => 'Status unknown',
            self::PowerSave => 'Power save',
        };
    }

    public function color(): string
    {
        return match (true) {
            $this->isProblem() => 'red',
            $this->isInfo(), $this === self::Paused => 'amber',
            $this === self::PendingDeletion => 'zinc',
            default => 'blue',
        };
    }

    /**
     * Flags that mean the printer cannot print until someone deals with it.
     */
    public function isProblem(): bool
    {
        return match ($this) {
            self::Error, self::PaperJam, self::PaperOut, self::PaperProblem, self::Offline, self::NotAvailable,
            self::UserIntervention, self::DoorOpen, self::NoToner, self::OutputBinFull, self::ServerUnknown => true,
            default => false,
        };
    }

    /**
     * Worth showing, never worth an alert.
     */
    public function isInfo(): bool
    {
        return $this === self::TonerLow || $this === self::PowerSave;
    }
}
