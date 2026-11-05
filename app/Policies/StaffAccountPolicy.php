<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StaffAccount;
use App\Models\User;

/**
 * The house matrix, flattened: this app signs in its one admin through
 * Authentik without roles (authentik-login 1.0.0 has no HasAuthentikRole), so
 * every signed-in user may do everything, like the other policies here.
 */
final class StaffAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StaffAccount $account): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, StaffAccount $account): bool
    {
        return true;
    }

    public function delete(User $user, StaffAccount $account): bool
    {
        return true;
    }
}
