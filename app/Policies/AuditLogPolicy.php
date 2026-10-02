<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Read-only on purpose: the audit log has no update or delete abilities to grant.
 */
final class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }
}
