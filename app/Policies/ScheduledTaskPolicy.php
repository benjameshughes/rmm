<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ScheduledTask;
use App\Models\User;

final class ScheduledTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ScheduledTask $task): bool
    {
        return true;
    }

    public function run(User $user, ScheduledTask $task): bool
    {
        return true;
    }

    public function delete(User $user, ScheduledTask $task): bool
    {
        return true;
    }
}
