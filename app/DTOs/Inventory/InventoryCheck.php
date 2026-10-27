<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

/**
 * One security setting with the badge it earns: green when sound, amber when
 * worth a look, red when unsafe and zinc when it could not be read.
 */
final class InventoryCheck
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        public readonly string $color,
    ) {}

    public function needsAttention(): bool
    {
        return in_array($this->color, ['red', 'amber'], true);
    }

    /**
     * The icon and text colour for a status tile, matching the badge colour.
     */
    public function icon(): string
    {
        return match ($this->color) {
            'red' => 'x-circle',
            'amber' => 'exclamation-triangle',
            'green' => 'check-circle',
            default => 'minus-circle',
        };
    }

    public function tone(): string
    {
        return match ($this->color) {
            'red' => 'text-red-500 dark:text-red-400',
            'amber' => 'text-amber-500 dark:text-amber-400',
            'green' => 'text-green-600 dark:text-green-400',
            default => 'text-zinc-400 dark:text-zinc-500',
        };
    }
}
