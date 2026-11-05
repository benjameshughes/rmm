<?php

declare(strict_types=1);

namespace App\Actions\LocalAccount;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Exceptions\LocalAccountCannotBeChanged;
use App\Models\DeviceCommand;
use App\Models\DeviceLocalAccount;
use App\Models\Script;
use App\Models\User;
use App\Queries\DeviceLocalAccountQueries;

/**
 * Queues disable-local-user for an unmanaged local account on its PC.
 */
final readonly class DisableLocalAccount
{
    public function __construct(
        private ExecuteScriptOnDevice $executeScript,
        private ValidateScriptParameterValues $validateParameters,
        private DeviceLocalAccountQueries $localAccounts,
    ) {}

    public function __invoke(DeviceLocalAccount $account, User $user): DeviceCommand
    {
        throw_if($account->isBuiltIn, LocalAccountCannotBeChanged::builtIn($account));
        throw_if($this->localAccounts->isManaged($account), LocalAccountCannotBeChanged::managed($account));

        $script = Script::findSystem('disable-local-user');
        $parameters = ($this->validateParameters)($script, ['Username' => $account->username], 'localAccount');

        return ($this->executeScript)($script, $account->device, $user, parameters: $parameters);
    }
}
