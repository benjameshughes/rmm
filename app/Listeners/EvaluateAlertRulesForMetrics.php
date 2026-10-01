<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Alert\EvaluateAlertRules;
use App\Events\MetricsReceived;

final class EvaluateAlertRulesForMetrics
{
    public function __construct(
        private readonly EvaluateAlertRules $evaluateAlertRules,
    ) {}

    public function handle(MetricsReceived $event): void
    {
        ($this->evaluateAlertRules)($event->device, $event->metric);
    }
}
