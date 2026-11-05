<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ResetStaffPassword
{
    public function __construct(
        private SyncStaffAccount $syncStaffAccount,
    ) {}

    /**
     * @param  string|null  $password  The new password, or null to generate one
     * @return string The password now set, so it can be handed to the member of staff
     */
    public function __invoke(StaffAccount $account, User $user, ?string $password = null): string
    {
        $password ??= Str::password(config('staff.generated_password_length'));

        DB::transaction(function () use ($account, $user, $password): void {
            $account->update(['password' => $password]);
            ($this->syncStaffAccount)($account, $user);
        });

        return $password;
    }
}
