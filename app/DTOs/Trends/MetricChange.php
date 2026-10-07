<?php

declare(strict_types=1);

namespace App\DTOs\Trends;

use App\Enums\TrendDirection;
use App\Enums\TrendMetric;

/**
 * One metric before and after. It reports the difference; it never says why.
 */
final readonly class MetricChange
{
    public function __construct(
        public TrendMetric $metric,
        public ?float $previous,
        public ?float $current,
    ) {}

    public function isMeasured(): bool
    {
        return $this->previous !== null && $this->current !== null;
    }

    public function difference(): ?float
    {
        return $this->isMeasured() ? $this->current - $this->previous : null;
    }

    public function direction(): TrendDirection
    {
        $difference = $this->difference();
        $isLowerBetter = $this->metric->isLowerBetter();

        return match (true) {
            $difference === null => TrendDirection::Unmeasured,
            abs($difference) <= $this->metric->steadyWithin() => TrendDirection::Steady,
            $isLowerBetter === null => TrendDirection::Changed,
            ($difference < 0) === $isLowerBetter => TrendDirection::Improved,
            default => TrendDirection::Worse,
        };
    }

    public function previousForHumans(): ?string
    {
        return $this->metric->format($this->previous);
    }

    public function currentForHumans(): ?string
    {
        return $this->metric->format($this->current);
    }

    public function differenceForHumans(): ?string
    {
        return $this->isMeasured() ? $this->metric->differenceForHumans($this->previous, $this->current) : null;
    }

    /**
     * Bar width for the before figure: percentages on their own scale, anything else against the larger of the two.
     */
    public function previousShare(): float
    {
        return $this->share($this->previous);
    }

    public function currentShare(): float
    {
        return $this->share($this->current);
    }

    private function share(?float $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if ($this->metric->isPercentage()) {
            return min(100.0, max(0.0, $value));
        }

        $largest = max($this->previous ?? 0.0, $this->current ?? 0.0);

        return $largest > 0 ? $value / $largest * 100 : 0.0;
    }
}
