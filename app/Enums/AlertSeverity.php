<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertSeverity: string
{
    case Warning = 'warning';
    case Critical = 'critical';

    public function color(): string
    {
        return match ($this) {
            self::Warning => 'amber',
            self::Critical => 'red',
        };
    }

    public function toastVariant(): string
    {
        return match ($this) {
            self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }
}
