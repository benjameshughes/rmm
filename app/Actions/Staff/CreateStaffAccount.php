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

    public function __invoke(StaffAccountDetails $details, string $password, User $user): StaffAccount
    {
        return DB::transaction(function () use ($details, $password, $user): StaffAccount {
            $account = StaffAccount::create([...$details->attributes(), 'password' => $password]);

            $account->devices()->sync($details->deviceIds);
            $account->deviceGroups()->sync($details->deviceGroupIds);
            $account->tags()->sync($details->tagIds);

            ($this->syncStaffAccount)($account, $user);

            return $account;
        });
    }
}
