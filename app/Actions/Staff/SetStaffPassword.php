<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\AuditAction;
use App\Models\Device;
use App\Models\Script;
use App\Models\StaffAccount;
use App\Models\User;
use App\Queries\AgentVersionQueries;
use App\Queries\StaffAccountQueries;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Queues set-local-password on every PC the account targets, each with the
 * chosen password as its own encrypted command secret. The password is never
 * in the command's parameters: ResolveCommandSecrets takes it (and deletes it)
 * when the agent fetches the command. One transaction, so no agent can fetch
 * a command before its secret is there.
 */
final readonly class SetStaffPassword
{
    public function __construct(
        private ExecuteScriptOnDevice $executeScript,
        private ValidateScriptParameterValues $validateParameters,
        private StaffAccountQueries $staffAccounts,
        private AgentVersionQueries $agentVersions,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @return int How many PCs the password was queued on
     */
    public function __invoke(StaffAccount $account, #[SensitiveParameter] string $password, User $user): int
    {
        $script = Script::findSystem('set-local-password');

        $parameters = ($this->validateParameters)($script, [
            'StaffAccountId' => $account->id,
            'Username' => $account->username,
        ], 'staffAccount');

        $devices = $this->staffAccounts->devices($account)->get()
            ->filter(fn (Device $device): bool => $this->agentVersions->supportsScriptParameters($device));

        return DB::transaction(function () use ($account, $password, $user, $script, $parameters, $devices): int {
            $devices->each(fn (Device $device) => ($this->executeScript)($script, $device, $user, parameters: $parameters)
                ->secrets()
                ->create([
                    'name' => 'StaffPassword',
                    'value' => $password,
                    'expires_at' => now()->addMinutes((int) config('staff.password_handover_ttl_minutes')),
                ]));

            ($this->recordAuditEvent)(AuditAction::StaffAccountPasswordSet, $account, actor: $user);

            return $devices->count();
        });
    }
}
