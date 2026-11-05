<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the local user on every PC but disabled, so nobody can sign in with it.
 */
final readonly class DisableStaffAccount
{
    public function __construct(
        private SyncStaffAccount $syncStaffAccount,
    ) {}

    /**
     * @return int How many PCs the sync was queued on
     */
    public function __invoke(StaffAccount $account, User $user): int
    {
        return DB::transaction(function () use ($account, $user): int {
            $account->update(['is_enabled' => false]);

            return ($this->syncStaffAccount)($account, $user);
        });
    }
}
