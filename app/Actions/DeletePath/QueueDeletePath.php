<?php

declare(strict_types=1);

namespace App\Actions\DeletePath;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\DeleteMode;
use App\Enums\PathKind;
use App\Enums\ScriptPlatform;
use App\Exceptions\PathCannotBeDeleted;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;

/**
 * Queues remove-path on a Windows PC for one file or folder that passes
 * the guard rails. Returns null when a delete of the same path is already
 * queued or running there.
 */
final class QueueDeletePath
{
    public function __construct(
        private readonly GuardDeletablePath $guard,
        private readonly ExecuteScriptOnDevice $executeScript,
        private readonly ValidateScriptParameterValues $validateParameters,
    ) {}

    /**
     * @throws PathCannotBeDeleted When the PC never deletes anything, or the path is protected or malformed
     */
    public function __invoke(Device $device, User $user, string $path, DeleteMode $mode, PathKind $kind = PathKind::Any): ?DeviceCommand
    {
        throw_if($device->isMonitorOnly, PathCannotBeDeleted::monitorOnly($device));
        throw_unless($device->platform() === ScriptPlatform::Windows, PathCannotBeDeleted::notWindows($device));

        $path = ($this->guard)($path);
        $script = Script::findSystem(config('devices.delete_path.slug'));

        if ($device->inFlightCommands()->where('script_id', $script->id)->where('parameters->Path', $path)->exists()) {
            return null;
        }

        $parameters = ($this->validateParameters)($script, ['Path' => $path, 'Mode' => $mode->value, 'ExpectedKind' => $kind->value], 'path');

        return ($this->executeScript)($script, $device, $user, parameters: $parameters);
    }
}
