<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The USMT system scripts behind Migrate User, each value its slug.
 */
enum UserStateScript: string
{
    case Install = 'install-usmt';
    case Capture = 'capture-user-state';
    case Apply = 'apply-user-state';
    case MigrateInPlace = 'migrate-user-in-place';

    public function queuedHeading(): string
    {
        return match ($this) {
            self::Install => 'Installing USMT',
            self::Capture => 'Capturing user',
            self::Apply => 'Loading user',
            self::MigrateInPlace => 'Migrating user',
        };
    }
}
