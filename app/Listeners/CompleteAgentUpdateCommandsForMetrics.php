<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Agent\CompleteAgentUpdateCommands;
use App\Events\MetricsReceived;

final class CompleteAgentUpdateCommandsForMetrics
{
    public function __construct(
        private readonly CompleteAgentUpdateCommands $completeAgentUpdateCommands,
    ) {}

    public function handle(MetricsReceived $event): void
    {
        if (! $event->device->wasChanged('agent_version')) {
            return;
        }

        ($this->completeAgentUpdateCommands)($event->device, $event->device->getPrevious()['agent_version'] ?? null);
    }
}
