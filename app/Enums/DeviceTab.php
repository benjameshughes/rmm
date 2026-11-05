<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Device;

enum DeviceTab: string
{
    case Overview = 'show';
    case Metrics = 'metrics';
    case Commands = 'commands';
    case Apps = 'apps';
    case Security = 'security';
    case Storage = 'storage';
    case Printers = 'printers';
    case Backups = 'backups';
    case Users = 'users';
    case Details = 'details';

    public function label(): string
    {
        return match ($this) {
            self::Overview => 'Overview',
            self::Metrics => 'Metrics',
            self::Commands => 'Commands',
            self::Apps => 'Apps',
            self::Security => 'Security',
            self::Storage => 'Storage',
            self::Printers => 'Printers',
            self::Backups => 'Backups',
            self::Users => 'Users',
            self::Details => 'Details',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Overview => 'squares-2x2',
            self::Metrics => 'chart-bar',
            self::Commands => 'command-line',
            self::Apps => 'cpu-chip',
            self::Security => 'shield-check',
            self::Storage => 'circle-stack',
            self::Printers => 'printer',
            self::Backups => 'cloud-arrow-up',
            self::Users => 'users',
            self::Details => 'information-circle',
        };
    }

    public function pageTitle(Device $device): string
    {
        return "{$device->hostname} · {$this->label()}";
    }

    public function routeName(): string
    {
        return "devices.{$this->value}";
    }
}
