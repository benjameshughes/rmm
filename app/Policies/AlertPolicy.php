<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

final class AlertPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user, Alert $alert): bool
    {
        return true;
    }
}
