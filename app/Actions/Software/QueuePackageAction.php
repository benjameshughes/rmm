<?php

declare(strict_types=1);

namespace App\Actions\Software;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\PackageAction;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;

/**
 * Queues an upgrade or uninstall of one package on one device. The agent version
 * gate in ExecuteScriptOnDevice applies, since the package ID is a parameter.
 */
final class QueuePackageAction
{
    public function __construct(
        private readonly ExecuteScriptOnDevice $executeScript,
        private readonly ValidateScriptParameterValues $validateParameters,
    ) {}

    public function __invoke(PackageAction $action, Device $device, string $packageId, User $user): DeviceCommand
    {
        $script = Script::findSystem($action->value);
        $parameters = ($this->validateParameters)($script, ['PackageId' => $packageId], 'packageId');

        return ($this->executeScript)($script, $device, $user, parameters: $parameters);
    }
}
