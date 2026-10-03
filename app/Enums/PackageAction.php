<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Changes the software pages can make to one installed package.
 */
enum PackageAction: string
{
    case Upgrade = 'winget-upgrade';
    case Uninstall = 'winget-uninstall';

    public function label(): string
    {
        return match ($this) {
            self::Upgrade => 'Upgrade',
            self::Uninstall => 'Uninstall',
        };
    }

    public function queuedHeading(): string
    {
        return match ($this) {
            self::Upgrade => 'Upgrade queued',
            self::Uninstall => 'Uninstall queued',
        };
    }
}
