<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Collection;

/**
 * The JOB_STATUS_* bits from winspool.h that a Windows print job reports.
 */
enum PrintJobStatusFlag: int
{
    case Paused = 0x1;
    case Error = 0x2;
    case Deleting = 0x4;
    case Spooling = 0x8;
    case Printing = 0x10;
    case Offline = 0x20;
    case PaperOut = 0x40;
    case Printed = 0x80;
    case Deleted = 0x100;
    case BlockedDevq = 0x200;
    case UserIntervention = 0x400;
    case Restart = 0x800;
    case Complete = 0x1000;
    case Retained = 0x2000;
    case RenderingLocally = 0x4000;

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
            self::Deleting => 'Deleting',
            self::Spooling => 'Spooling',
            self::Printing => 'Printing',
            self::Offline => 'Offline',
            self::PaperOut => 'Out of paper',
            self::Printed => 'Printed',
            self::Deleted => 'Deleted',
            self::BlockedDevq => 'Blocked by driver',
            self::UserIntervention => 'Needs attention',
            self::Restart => 'Restarted',
            self::Complete => 'Sent to printer',
            self::Retained => 'Retained',
            self::RenderingLocally => 'Rendering',
        };
    }

    public function color(): string
    {
        return match (true) {
            $this->isProblem() => 'red',
            $this === self::Paused, $this === self::Restart => 'amber',
            $this === self::Printed, $this === self::Complete => 'green',
            $this === self::Deleting, $this === self::Deleted, $this === self::Retained => 'zinc',
            default => 'blue',
        };
    }

    /**
     * Flags that mean the job will not print until someone deals with it.
     */
    public function isProblem(): bool
    {
        return match ($this) {
            self::Error, self::Offline, self::PaperOut, self::BlockedDevq, self::UserIntervention => true,
            default => false,
        };
    }
}
