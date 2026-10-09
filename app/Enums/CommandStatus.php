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

    /**
     * Sent or running: the agent has it, so its script may be reporting progress.
     */
    public function isWithAgent(): bool
    {
        return in_array($this, [self::Sent, self::Running]);
    }

    /**
     * @return array<int, self>
     */
    public static function withAgent(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isWithAgent()));
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::Sent, self::Running => 'blue',
            self::Completed => 'green',
            self::Failed => 'red',
            self::TimedOut => 'amber',
            self::Cancelled => 'zinc',
        };
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
