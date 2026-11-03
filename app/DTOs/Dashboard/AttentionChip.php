<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

/**
 * One problem on a dashboard attention row, as a small badge: what it is, how bad, and the detail on hover.
 */
final readonly class AttentionChip
{
    public function __construct(
        public string $label,
        public string $color,
        public ?string $detail = null,
    ) {}

    /**
     * Chips are plain text in their signal colour; anything that is not a problem stays grey.
     */
    public function textClass(): string
    {
        return match ($this->color) {
            'red' => 'text-red-600 dark:text-red-400',
            'amber' => 'text-amber-600 dark:text-amber-400',
            default => 'text-zinc-500 dark:text-zinc-400',
        };
    }
}
