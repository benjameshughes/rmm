<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use App\Enums\ChartInk;

/**
 * One slice of a dashboard ring: what it is, how many, and its ink.
 */
final readonly class RingSegment
{
    public function __construct(
        public string $label,
        public int $value,
        public ChartInk $ink,
    ) {}
}
