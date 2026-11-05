<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\DTOs\Staff\StaffAccountDetails;
use App\Models\Device;
use App\Models\StaffAccount;
use App\Models\User;
use App\Queries\StaffAccountQueries;
use Illuminate\Support\Facades\DB;

/**
 * Saves the account and syncs it to every PC it targets now. PCs it no longer
 * targets get the local user removed. The username is fixed once created, as
 * the PCs know the account by it, so the details' username is ignored.
 */
final readonly class UpdateStaffAccount
{
    public function __construct(
        private SyncStaffAccount $syncStaffAccount,
        private RemoveStaffAccountFromDevices $removeFromDevices,
        private StaffAccountQueries $staffAccounts,
    ) {}

    public function __invoke(StaffAccount $account, StaffAccountDetails $details, User $user): StaffAccount
    {
        return DB::transaction(function () use ($account, $details, $user): StaffAccount {
            $before = $this->staffAccounts->devices($account)->get();

            $account->update([
                'full_name' => $details->fullName,
                'is_admin' => $details->isAdmin,
                'is_enabled' => $details->isEnabled,
            ]);

            $account->devices()->sync($details->deviceIds);
            $account->deviceGroups()->sync($details->deviceGroupIds);
            $account->tags()->sync($details->tagIds);

            $afterIds = $this->staffAccounts->devices($account)->pluck('id');
            $dropped = $before->reject(fn (Device $device): bool => $afterIds->contains($device->id));

            ($this->removeFromDevices)($account, $dropped, $user);
            ($this->syncStaffAccount)($account, $user);

            return $account;
        });
    }
}
