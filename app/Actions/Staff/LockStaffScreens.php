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

/**
 * Queues set-screen-lock, with the idle minutes from config/staff.php, on
 * every PC any staff account targets, each once.
 */
final readonly class LockStaffScreens
{
    public function __construct(
        private BulkExecuteScript $bulkExecute,
        private ValidateScriptParameterValues $validateParameters,
        private StaffAccountQueries $staffAccounts,
    ) {}

    /**
     * @return int How many PCs the screen lock was queued on
     */
    public function __invoke(User $user): int
    {
        $script = Script::findSystem('set-screen-lock');
        $parameters = ($this->validateParameters)($script, ['IdleMinutes' => config('staff.screen_lock_idle_minutes')], 'screenLock');

        $devices = StaffAccount::query()->get()
            ->flatMap(fn (StaffAccount $account): iterable => $this->staffAccounts->devices($account)->get())
            ->unique(fn (Device $device): int => $device->id)
            ->values();

        return ($this->bulkExecute)($script, $devices, $user, $parameters);
    }
}
