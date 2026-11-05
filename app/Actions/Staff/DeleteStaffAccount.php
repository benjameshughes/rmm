<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\StaffAccount;
use App\Models\User;
use App\Queries\StaffAccountQueries;
use Illuminate\Support\Facades\DB;

/**
 * Queues remove-local-user on every PC the account targets, then deletes it.
 * Any sync still waiting for a PC then gets a blank temp password, which
 * sync-local-user refuses, so a deleted account is never recreated.
 */
final readonly class DeleteStaffAccount
{
    public function __construct(
        private RemoveStaffAccountFromDevices $removeFromDevices,
        private StaffAccountQueries $staffAccounts,
    ) {}

    /**
     * @return int How many PCs the removal was queued on
     */
    public function __invoke(StaffAccount $account, User $user): int
    {
        return DB::transaction(function () use ($account, $user): int {
            $queued = ($this->removeFromDevices)($account, $this->staffAccounts->devices($account)->get(), $user);
            $account->delete();

            return $queued;
        });
    }
}
