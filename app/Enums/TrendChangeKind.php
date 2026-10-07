<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\DeviceCommand;

/**
 * What a finished command changed on the PC, for the "In the same period" list.
 */
enum TrendChangeKind: string
{
    case Uninstalled = 'uninstalled';
    case Installed = 'installed';
    case Upgraded = 'upgraded';
    case Script = 'script';
    case AdHoc = 'ad-hoc';

    public static function of(DeviceCommand $command): self
    {
        return match (true) {
            $command->isAdHoc() => self::AdHoc,
            default => match ($command->packageAction()) {
                PackageAction::Uninstall => self::Uninstalled,
                PackageAction::Install => self::Installed,
                PackageAction::Upgrade => self::Upgraded,
                null => self::Script,
            },
        };
    }

    public function summary(string $subject, int $deviceCount): string
    {
        $devices = $deviceCount.($deviceCount === 1 ? ' PC' : ' PCs');

        return match ($this) {
            self::Uninstalled => "{$subject} uninstalled on {$devices}",
            self::Installed => "{$subject} installed on {$devices}",
            self::Upgraded => "{$subject} upgraded on {$devices}",
            self::Script => "{$subject} ran on {$devices}",
            self::AdHoc => "Ad-hoc commands ran on {$devices}",
        };
    }

    /**
     * The same change as one device's own note: "Uninstalled Datadog Agent".
     */
    public function deviceLabel(string $subject): string
    {
        return match ($this) {
            self::Uninstalled => "Uninstalled {$subject}",
            self::Installed => "Installed {$subject}",
            self::Upgraded => "Upgraded {$subject}",
            self::Script => $subject,
            self::AdHoc => 'Ad-hoc command',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Uninstalled => 'trash',
            self::Installed => 'arrow-down-tray',
            self::Upgraded => 'arrow-up-circle',
            self::Script => 'command-line',
            self::AdHoc => 'code-bracket',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Uninstalled => 'text-rose-500',
            self::Installed => 'text-sky-500',
            self::Upgraded => 'text-emerald-500',
            self::Script => 'text-violet-500',
            self::AdHoc => 'text-zinc-400 dark:text-zinc-500',
        };
    }
}
