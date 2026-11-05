<?php

declare(strict_types=1);

namespace App\Actions\LocalAccount;

use App\Actions\Staff\CreateStaffAccount;
use App\DTOs\Staff\StaffAccountDetails;
use App\Exceptions\LocalAccountCannotBeChanged;
use App\Models\DeviceLocalAccount;
use App\Models\StaffAccount;
use App\Models\User;
use App\Queries\DeviceLocalAccountQueries;

/**
 * Turns an unmanaged local account into a staff account targeting its PC,
 * keeping its name, full name, admin rights and enabled state. Its password
 * is left alone unless resetPassword asks for the temp password.
 */
final readonly class AdoptLocalAccount
{
    public function __construct(
        private CreateStaffAccount $createStaffAccount,
        private DeviceLocalAccountQueries $localAccounts,
    ) {}

    public function __invoke(DeviceLocalAccount $account, User $user, bool $resetPassword = false): StaffAccount
    {
        throw_if($account->isBuiltIn, LocalAccountCannotBeChanged::builtIn($account));
        throw_if($this->localAccounts->isManaged($account), LocalAccountCannotBeChanged::managed($account));

        return ($this->createStaffAccount)(
            details: new StaffAccountDetails(
                username: $account->username,
                fullName: $account->full_name ?? $account->username,
                isAdmin: (bool) $account->is_admin,
                isEnabled: $account->is_enabled,
                deviceIds: [$account->device_id],
                deviceGroupIds: [],
                tagIds: [],
            ),
            user: $user,
            resetPassword: $resetPassword,
        );
    }
}
