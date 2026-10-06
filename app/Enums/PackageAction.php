<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Changes the software pages can make to a package on a device.
 */
enum PackageAction: string
{
    case Install = 'winget-install';
    case Upgrade = 'winget-upgrade';
    case Uninstall = 'winget-uninstall';

    public function label(): string
    {
        return match ($this) {
            self::Install => 'Install',
            self::Upgrade => 'Upgrade',
            self::Uninstall => 'Uninstall',
        };
    }

    /**
     * Whether its script can close the app first (an install has nothing to close).
     */
    public function canCloseApp(): bool
    {
        return $this !== self::Install;
    }

    public function inProgressLabel(): string
    {
        return match ($this) {
            self::Install => 'Installing',
            self::Upgrade => 'Upgrading',
            self::Uninstall => 'Uninstalling',
        };
    }

    public function queuedHeading(): string
    {
        return match ($this) {
            self::Install => 'Install queued',
            self::Upgrade => 'Upgrade queued',
            self::Uninstall => 'Uninstall queued',
        };
    }
}
