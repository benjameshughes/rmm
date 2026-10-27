<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

/**
 * One Critical or Error event from the System log, already reduced to display strings.
 */
final class InventoryEvent
{
    public function __construct(
        public readonly ?string $time,
        public readonly ?string $timeForHumans,
        public readonly string $level,
        public readonly ?string $source,
        public readonly ?string $eventId,
        public readonly ?string $message,
    ) {}

    public function isCritical(): bool
    {
        return $this->level === 'Critical';
    }

    public function levelColor(): string
    {
        return $this->isCritical() ? 'red' : 'amber';
    }

    /**
     * Whether the message is long enough to clamp until clicked.
     */
    public function hasLongMessage(): bool
    {
        return mb_strlen($this->message ?? '') > config('inventory.error_message_preview_length');
    }
}
