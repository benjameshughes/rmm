<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

/**
 * A monitor-only device is watched, never driven: nothing that queues a command or sends a power signal is allowed on it.
 */
final class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Device $device): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function approve(User $user, Device $device): bool
    {
        return true;
    }

    public function reject(User $user, Device $device): bool
    {
        return true;
    }

    public function runCommands(User $user, Device $device): bool
    {
        return ! $device->isMonitorOnly;
    }

    public function runAdHocCommand(User $user, Device $device): bool
    {
        return ! $device->isMonitorOnly;
    }

    public function wake(User $user, Device $device): bool
    {
        return ! $device->isMonitorOnly;
    }

    public function resetEnrolment(User $user, Device $device): bool
    {
        return true;
    }

    public function manageGroupsAndTags(User $user, Device $device): bool
    {
        return true;
    }

    public function delete(User $user, Device $device): bool
    {
        return true;
    }
}
