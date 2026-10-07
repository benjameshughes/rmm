<?php

declare(strict_types=1);

namespace App\Actions\Trends;

use App\DTOs\Trends\FleetTrend;
use App\DTOs\Trends\TrendComparison;
use App\DTOs\Trends\TrendWindow;
use App\Enums\TrendPeriod;
use App\Enums\TrendScope;
use App\Queries\TrendQueries;
use Illuminate\Support\Facades\Cache;

/**
 * Measures the fleet over the latest period and the one before. It reads every report in both,
 * so the result is cached briefly; the page shows when its figures are from.
 */
final class CompareTrendPeriods
{
    public function __construct(private readonly TrendQueries $trends) {}

    public function __invoke(TrendPeriod $period, TrendScope $scope): TrendComparison
    {
        return Cache::remember(
            key: config('trends.cache_key').".{$period->value}.{$scope->value}",
            ttl: config('trends.cache_seconds'),
            callback: fn (): TrendComparison => $this->compare($period, $scope),
        );
    }

    private function compare(TrendPeriod $period, TrendScope $scope): TrendComparison
    {
        $window = TrendWindow::endingAt($period, now()->startOfMinute());
        $stats = $this->trends->deviceStats($window, $scope);

        return new TrendComparison(
            window: $window,
            stats: $stats,
            series: $this->trends->fleetSeries($window, FleetTrend::from($stats)->deviceIds),
            computedAt: now(),
        );
    }
}
