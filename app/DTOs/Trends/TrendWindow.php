<?php

declare(strict_types=1);

namespace App\DTOs\Trends;

use App\Enums\TrendPeriod;
use Illuminate\Support\Carbon;

/**
 * The two back-to-back stretches being compared: previous runs up to where current starts.
 */
final readonly class TrendWindow
{
    public function __construct(
        public TrendPeriod $period,
        public Carbon $previousStartsAt,
        public Carbon $currentStartsAt,
        public Carbon $endsAt,
    ) {}

    public static function endingAt(TrendPeriod $period, Carbon $endsAt): self
    {
        $currentStartsAt = $endsAt->copy()->subHours($period->hours());

        return new self(
            period: $period,
            previousStartsAt: $currentStartsAt->copy()->subHours($period->hours()),
            currentStartsAt: $currentStartsAt,
            endsAt: $endsAt->copy(),
        );
    }

    public function bucketCount(): int
    {
        return intdiv($this->period->hours() * 3600, $this->period->bucketSeconds());
    }
}
