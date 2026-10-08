<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The bits of a disk scan node's flags (schema rmm.du/1).
 */
enum DiskNodeFlag: int
{
    case Other = 1;
    case LinkNotFollowed = 2;
    case AccessDenied = 4;
    case Cloud = 8;
    case Kept = 16;

    /**
     * The flags set in a node's bitmask, in declaration order.
     *
     * @return array<int, self>
     */
    public static function fromMask(int $mask): array
    {
        return array_values(array_filter(self::cases(), fn (self $flag): bool => ($mask & $flag->value) === $flag->value));
    }

    public function label(): string
    {
        return match ($this) {
            self::Other => 'Rolled up',
            self::LinkNotFollowed => 'Link not followed',
            self::AccessDenied => 'Access denied',
            self::Cloud => 'Cloud',
            self::Kept => 'Space hog',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Other => 'Smaller folders added together',
            self::LinkNotFollowed => 'A junction or symbolic link; what it points at is counted where it really lives',
            self::AccessDenied => 'Some of it could not be read, so it may be bigger',
            self::Cloud => 'Cloud files: much of it may not be on this disk',
            self::Kept => 'A well-known space hog the scan always keeps',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Other => 'zinc',
            self::LinkNotFollowed => 'sky',
            self::AccessDenied => 'red',
            self::Cloud => 'blue',
            self::Kept => 'amber',
        };
    }

    /**
     * Kept nodes show up as space hogs instead of a badge.
     */
    public function isBadge(): bool
    {
        return $this !== self::Kept;
    }
}
