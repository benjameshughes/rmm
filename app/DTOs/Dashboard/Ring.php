<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use Illuminate\Support\Collection;

/**
 * A ring chart drawn as SVG arcs. Each arc's length and start come out as percentages of the
 * circle, so the Blade component only prints them into stroke-dasharray and stroke-dashoffset.
 */
final readonly class Ring
{
    /**
     * @param  Collection<int, RingSegment>  $segments
     */
    public function __construct(public Collection $segments) {}

    public function total(): int
    {
        return $this->segments->sum(fn (RingSegment $segment): int => $segment->value);
    }

    /**
     * How many are in the problem segments: the figure in the middle of a problem ring.
     */
    public function problemTotal(): int
    {
        return $this->segments->filter(fn (RingSegment $segment): bool => $segment->ink->isProblem())->sum(fn (RingSegment $segment): int => $segment->value);
    }

    /**
     * @return Collection<int, array{segment: RingSegment, length: float, offset: float}>
     */
    public function arcs(): Collection
    {
        $total = $this->total();

        return $this->segments
            ->reject(fn (RingSegment $segment): bool => $segment->value === 0 || $total === 0)
            ->values()
            ->reduce(function (Collection $arcs, RingSegment $segment) use ($total): Collection {
                $start = $arcs->sum('length');

                return $arcs->push([
                    'segment' => $segment,
                    'length' => round($segment->value / $total * 100, 3),
                    'offset' => round(-$start, 3),
                ]);
            }, collect());
    }

    public function tooltip(): string
    {
        return $this->segments
            ->reject(fn (RingSegment $segment): bool => $segment->value === 0)
            ->map(fn (RingSegment $segment): string => "{$segment->label}: {$segment->value}")
            ->implode(' · ');
    }
}
