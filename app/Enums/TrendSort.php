<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The Trends device table's sortable columns. A change column sorts by how far it moved,
 * biggest drop first, so the PCs that got lighter lead.
 */
enum TrendSort: string
{
    case Hostname = 'pc';
    case Ram = 'ram';
    case Cpu = 'cpu';
    case Reboots = 'reboots';
    case BlankRate = 'blank_rate';

    public function label(): string
    {
        return $this->metric()?->label() ?? 'PC';
    }

    public function metric(): ?TrendMetric
    {
        return match ($this) {
            self::Hostname => null,
            default => TrendMetric::from($this->value),
        };
    }

    public function directionLabel(string $direction): string
    {
        return match (true) {
            $this === self::Hostname => $direction === 'asc' ? 'A to Z' : 'Z to A',
            default => $direction === 'asc' ? 'biggest drop first' : 'biggest rise first',
        };
    }

    public function defaultDirection(): string
    {
        return 'asc';
    }
}
