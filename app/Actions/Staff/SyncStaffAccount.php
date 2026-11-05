<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Actions\Device\BulkExecuteScript;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Models\Device;
use App\Models\Script;
use App\Models\StaffAccount;
use App\Models\User;
use App\Queries\StaffAccountQueries;
use Illuminate\Support\Collection;

/**
 * Queues sync-local-user on every PC the account targets. The password is
 * only touched when resetPassword is true (create and reset): the PC then
 * sets the temp password, which ResolveCommandSecrets hands over as
 * StaffTempPassword when the agent fetches the command.
 */
final readonly class SyncStaffAccount
{
    public function __construct(
        private BulkExecuteScript $bulkExecute,
        private ValidateScriptParameterValues $validateParameters,
        private StaffAccountQueries $staffAccounts,
    ) {}

    /**
     * @param  Collection<int, Device>|null  $devices  Only these PCs, or null for every PC the account targets
     * @return int How many PCs the sync was queued on
     */
    public function __invoke(StaffAccount $account, User $user, ?Collection $devices = null, bool $resetPassword = false): int
    {
        $script = Script::findSystem('sync-local-user');

        $parameters = ($this->validateParameters)($script, [
            'StaffAccountId' => $account->id,
            'Username' => $account->username,
            'FullName' => $account->full_name,
            'IsAdmin' => $account->is_admin,
            'IsEnabled' => $account->is_enabled,
            'ResetPassword' => $resetPassword,
        ], 'staffAccount');

        return ($this->bulkExecute)($script, $devices ?? $this->staffAccounts->devices($account)->get(), $user, $parameters);
    }
}
