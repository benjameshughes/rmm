<?php

declare(strict_types=1);

namespace App\Actions\DeletePath;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\ScriptPlatform;
use App\Exceptions\PathCannotBeDeleted;
use App\Exceptions\QuarantineCannotBeChanged;
use App\Models\DeviceCommand;
use App\Models\DeviceQuarantine;
use App\Models\Script;
use App\Models\User;

/**
 * Queues restore-quarantine to move one quarantined item back where it
 * came from. The script refuses when something already exists there.
 * Returns null when a restore of it is already queued or running.
 */
final class QueueQuarantineRestore
{
    public function __construct(
        private readonly GuardDeletablePath $guard,
        private readonly ExecuteScriptOnDevice $executeScript,
        private readonly ValidateScriptParameterValues $validateParameters,
    ) {}

    /**
     * @throws PathCannotBeDeleted When the PC never takes commands, or the original path is now protected
     * @throws QuarantineCannotBeChanged When it was already purged or restored
     */
    public function __invoke(DeviceQuarantine $quarantine, User $user): ?DeviceCommand
    {
        $device = $quarantine->device;

        throw_if($device->isMonitorOnly, PathCannotBeDeleted::monitorOnly($device));
        throw_unless($device->platform() === ScriptPlatform::Windows, PathCannotBeDeleted::notWindows($device));
        throw_unless($quarantine->isHeld(), QuarantineCannotBeChanged::released($quarantine));

        $path = ($this->guard)($quarantine->path);
        $script = Script::findSystem(config('devices.delete_path.restore_slug'));

        if ($device->inFlightCommands()->where('script_id', $script->id)->where('parameters->Folder', $quarantine->folder)->exists()) {
            return null;
        }

        $parameters = ($this->validateParameters)($script, ['Folder' => $quarantine->folder, 'Path' => $path], 'quarantine');

        return ($this->executeScript)($script, $device, $user, parameters: $parameters);
    }
}
