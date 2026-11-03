<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use App\Enums\SignalColor;

/**
 * The big numbers across the top of the dashboard.
 */
final readonly class HeroFigures
{
    public function __construct(
        public int $online,
        public int $total,
        public int $offline,
        public int $openAlerts,
        public int $fullDisks,
        public int $failedUnits,
        public AlertTrend $alertTrend,
    ) {}

    public function offlineClass(): string
    {
        return SignalColor::Red->figureClass($this->offline);
    }

    public function openAlertsClass(): string
    {
        return SignalColor::Red->figureClass($this->openAlerts);
    }

    public function fullDisksClass(): string
    {
        return SignalColor::Amber->figureClass($this->fullDisks);
    }

    public function failedUnitsClass(): string
    {
        return SignalColor::Red->figureClass($this->failedUnits);
    }
}
