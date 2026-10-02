<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AlertSeverity;
use App\Enums\NotificationLevel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Any stored notification, typed for the bell. Every notification stores
 * title, body, level and url; rows written before that shape existed only
 * hold the alert fields, so those are read the old way.
 */
final class BellNotification
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $body,
        public readonly NotificationLevel $level,
        public readonly string $url,
        public readonly bool $isUnread,
        public readonly Carbon $createdAt,
    ) {}

    public static function fromDatabase(DatabaseNotification $notification): self
    {
        $data = $notification->data;

        return new self(
            id: $notification->id,
            title: $data['title'] ?? $data['hostname'],
            body: $data['body'] ?? $data['condition'],
            level: isset($data['level']) ? NotificationLevel::from($data['level']) : AlertSeverity::from($data['severity'])->notificationLevel(),
            url: $data['url'] ?? route('devices.show', $data['deviceId']),
            isUnread: $notification->unread(),
            createdAt: $notification->created_at,
        );
    }
}
