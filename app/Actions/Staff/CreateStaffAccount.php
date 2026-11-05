<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\DTOs\Staff\StaffAccountDetails;
use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateStaffAccount
{
    public function __construct(
        private SyncStaffAccount $syncStaffAccount,
    ) {}

    /**
     * @param  bool  $resetPassword  Whether a user already on the PCs gets the temp password too
     */
    public function __invoke(StaffAccountDetails $details, User $user, bool $resetPassword = true): StaffAccount
    {
        return DB::transaction(function () use ($details, $user, $resetPassword): StaffAccount {
            $account = StaffAccount::create($details->attributes());

            $account->devices()->sync($details->deviceIds);
            $account->deviceGroups()->sync($details->deviceGroupIds);
            $account->tags()->sync($details->tagIds);

            ($this->syncStaffAccount)($account, $user, resetPassword: $resetPassword);

            return $account;
        });
    }
}
