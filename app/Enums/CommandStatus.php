<?php

declare(strict_types=1);

namespace App\Enums;

enum CommandStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Failed,
            self::TimedOut,
            self::Cancelled,
        ]);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::TimedOut => 'Timed Out',
            self::Cancelled => 'Cancelled',
        };
    }
}
