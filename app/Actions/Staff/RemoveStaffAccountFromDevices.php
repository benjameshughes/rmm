<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Actions\Device\BulkExecuteScript;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Models\Device;
use App\Models\Script;
use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Queues remove-local-user for the account on the given PCs. The script
 * refuses the signed-in user and the built-in accounts on its own.
 */
final readonly class RemoveStaffAccountFromDevices
{
    public function __construct(
        private BulkExecuteScript $bulkExecute,
        private ValidateScriptParameterValues $validateParameters,
    ) {}

    /**
     * @param  Collection<int, Device>  $devices
     * @return int How many PCs the removal was queued on
     */
    public function __invoke(StaffAccount $account, Collection $devices, User $user): int
    {
        if ($devices->isEmpty()) {
            return 0;
        }

        $script = Script::findSystem('remove-local-user');
        $parameters = ($this->validateParameters)($script, ['Username' => $account->username], 'staffAccount');

        return ($this->bulkExecute)($script, $devices, $user, $parameters);
    }
}
