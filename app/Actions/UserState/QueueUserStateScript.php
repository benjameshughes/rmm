<?php

declare(strict_types=1);

namespace App\Actions\UserState;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\UserStateScript;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;

/**
 * Queues one of the USMT scripts on a device. Values go through the
 * script's own parameter rules; credentials never travel this way.
 */
final class QueueUserStateScript
{
    public function __construct(
        private readonly ExecuteScriptOnDevice $executeScript,
        private readonly ValidateScriptParameterValues $validateParameters,
    ) {}

    /**
     * @param  array<string, string|int|null>  $values  Keyed by parameter name; nulls are left out
     */
    public function __invoke(UserStateScript $userStateScript, Device $device, User $user, array $values = []): DeviceCommand
    {
        $script = Script::findSystem($userStateScript->value);
        $values = collect($values)->reject(fn (string|int|null $value): bool => $value === null || $value === '')->all();

        return ($this->executeScript)($script, $device, $user, parameters: ($this->validateParameters)($script, $values, 'migrate'));
    }
}
