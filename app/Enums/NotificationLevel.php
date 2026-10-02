<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Info => 'blue',
            self::Warning => 'amber',
            self::Critical => 'red',
        };
    }

    /**
     * Flux toasts have no info variant, so info toasts use the plain style.
     */
    public function toastVariant(): ?string
    {
        return match ($this) {
            self::Info => null,
            self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }
}
