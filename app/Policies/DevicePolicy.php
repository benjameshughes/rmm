<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

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
        return true;
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
