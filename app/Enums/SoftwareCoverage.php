<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much of the fleet (or the filtered group or tag) has a package.
 */
enum SoftwareCoverage: string
{
    case Every = 'every';
    case Some = 'some';

    public function label(): string
    {
        return match ($this) {
            self::Every => 'On every device',
            self::Some => 'On some devices',
        };
    }
}
