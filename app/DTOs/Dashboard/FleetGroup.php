<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use Illuminate\Support\Collection;

/**
 * Every device on one platform for the fleet grid, with its counts for the group label.
 */
final readonly class FleetGroup
{
    /**
     * @param  Collection<int, FleetSquare>  $squares
     */
    public function __construct(
        public string $name,
        public Collection $squares,
        public int $offline,
    ) {}

    public function label(): string
    {
        return $this->offline > 0
            ? "{$this->name} {$this->squares->count()} · {$this->offline} offline"
            : "{$this->name} {$this->squares->count()}";
    }
}
