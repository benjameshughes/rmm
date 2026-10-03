<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeviceCommand;
use App\Models\User;

final class DeviceCommandPolicy
{
    /**
     * Only whoever queued it, and only before the agent has fetched it. A
     * monitor-only device never fetches commands, so it has none to cancel.
     */
    public function cancel(User $user, DeviceCommand $command): bool
    {
        return $command->isPending() && $command->queuedBy()->is($user) && ! $command->device->isMonitorOnly;
    }
}
