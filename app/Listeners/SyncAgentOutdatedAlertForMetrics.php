<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Agent\SyncAgentOutdatedAlert;
use App\Events\MetricsReceived;
use App\Queries\AgentVersionQueries;

final class SyncAgentOutdatedAlertForMetrics
{
    public function __construct(
        private readonly SyncAgentOutdatedAlert $syncAgentOutdatedAlert,
        private readonly AgentVersionQueries $agentVersions,
    ) {}

    public function handle(MetricsReceived $event): void
    {
        ($this->syncAgentOutdatedAlert)($event->device, $this->agentVersions->latest());
    }
}
