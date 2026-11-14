<?php

declare(strict_types=1);

namespace App\Enums;

enum ScriptCategory: string
{
    case Power = 'power';
    case Network = 'network';
    case Maintenance = 'maintenance';
    case Security = 'security';
    case Info = 'info';
    case Services = 'services';
    case Processes = 'processes';
    case Updates = 'updates';
    case User = 'user';
    case Backup = 'backup';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Information',
            self::User => 'Users',
            default => $this->name,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Power => 'power',
            self::Network => 'globe-alt',
            self::Maintenance => 'wrench-screwdriver',
            self::Security => 'shield-check',
            self::Info => 'information-circle',
            self::Services => 'cog-6-tooth',
            self::Processes => 'cpu-chip',
            self::Updates => 'arrow-down-tray',
            self::User => 'user',
            self::Backup => 'archive-box',
        };
    }

    /**
     * Where the category sits in grouped lists: the order the cases are declared in.
     */
    public function position(): int
    {
        return array_search($this, self::cases(), true);
    }
}
