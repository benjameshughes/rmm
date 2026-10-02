<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AlertSeverity;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * A stored AlertTriggered notification, typed for the bell.
 */
final class AlertNotification
{
    public function __construct(
        public readonly string $id,
        public readonly int $deviceId,
        public readonly string $hostname,
        public readonly AlertSeverity $severity,
        public readonly string $condition,
        public readonly bool $isUnread,
        public readonly Carbon $createdAt,
    ) {}

    public static function fromDatabase(DatabaseNotification $notification): self
    {
        return new self(
            id: $notification->id,
            deviceId: $notification->data['deviceId'],
            hostname: $notification->data['hostname'],
            severity: AlertSeverity::from($notification->data['severity']),
            condition: $notification->data['condition'],
            isUnread: $notification->unread(),
            createdAt: $notification->created_at,
        );
    }
}
