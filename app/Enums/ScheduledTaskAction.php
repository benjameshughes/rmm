<?php

declare(strict_types=1);

namespace App\Enums;

enum ScheduledTaskAction: string
{
    case RunScript = 'run_script';
    case Wake = 'wake';

    public function label(): string
    {
        return match ($this) {
            self::RunScript => 'Run Script',
            self::Wake => 'Wake Devices',
        };
    }

    public function requiresScript(): bool
    {
        return match ($this) {
            self::RunScript => true,
            self::Wake => false,
        };
    }
}
