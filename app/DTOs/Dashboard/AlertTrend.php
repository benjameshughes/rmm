<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

/**
 * Alerts raised in the latest window and the one before it, for the hero tile's delta.
 */
final readonly class AlertTrend
{
    public function __construct(
        public int $latest,
        public int $previous,
    ) {}

    public function delta(): int
    {
        return $this->latest - $this->previous;
    }

    public function deltaLabel(): string
    {
        return match (true) {
            $this->delta() > 0 => '▲'.$this->delta().' vs yesterday',
            $this->delta() < 0 => '▼'.abs($this->delta()).' vs yesterday',
            default => 'same as yesterday',
        };
    }

    /**
     * More alerts than yesterday is the only change worth colouring.
     */
    public function deltaClass(): string
    {
        return $this->delta() > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-500 dark:text-zinc-400';
    }
}
