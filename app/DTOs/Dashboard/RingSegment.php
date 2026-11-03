<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

/**
 * One slice of a dashboard ring: how many, what it is, and its stroke colour class.
 */
final readonly class RingSegment
{
    public function __construct(
        public string $label,
        public int $value,
        public string $strokeClass,
        public bool $isProblem = false,
    ) {}
}
