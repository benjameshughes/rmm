<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who a typed command runs as on a Windows device. System is how every
 * command ran before run-as existed, so a command without one runs as System.
 */
enum CommandRunAs: string
{
    case System = 'system';
    case User = 'user';
    case UserElevated = 'user_elevated';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::User => 'Logged-in user',
            self::UserElevated => 'Logged-in user (elevated)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::System => 'zinc',
            self::User => 'sky',
            self::UserElevated => 'amber',
        };
    }

    public function isSystem(): bool
    {
        return $this === self::System;
    }
}
