<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

final class DevicePolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Device $device): bool
    {
        return true;
    }
}
