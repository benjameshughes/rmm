<?php

declare(strict_types=1);

namespace App\Actions\DeletePath;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\ScriptPlatform;
use App\Exceptions\PathCannotBeDeleted;
use App\Exceptions\QuarantineCannotBeChanged;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceQuarantine;
use App\Models\Script;
use App\Models\User;

/**
 * Queues purge-quarantine on a Windows PC: everything quarantined longer
 * than the configured days, or one quarantined item now. Returns null when
 * the same purge is already queued or running there.
 */
final class QueueQuarantinePurge
{
    public function __construct(
        private readonly ExecuteScriptOnDevice $executeScript,
        private readonly ValidateScriptParameterValues $validateParameters,
    ) {}

    /**
     * @throws PathCannotBeDeleted When the PC never deletes anything
     * @throws QuarantineCannotBeChanged When the one item asked for was already purged or restored
     */
    public function __invoke(Device $device, User $user, ?DeviceQuarantine $only = null): ?DeviceCommand
    {
        throw_if($device->isMonitorOnly, PathCannotBeDeleted::monitorOnly($device));
        throw_unless($device->platform() === ScriptPlatform::Windows, PathCannotBeDeleted::notWindows($device));

        if ($only !== null) {
            throw_unless($only->isHeld(), QuarantineCannotBeChanged::released($only));
        }

        $script = Script::findSystem(config('devices.delete_path.purge_slug'));
        $inFlight = $device->inFlightCommands()->where('script_id', $script->id);
        $alreadyQueued = $only === null ? $inFlight->whereNull('parameters->Folder')->exists() : $inFlight->where('parameters->Folder', $only->folder)->exists();

        if ($alreadyQueued) {
            return null;
        }

        $values = $only === null
            ? ['Days' => (string) config('devices.delete_path.quarantine_days')]
            : ['Days' => '0', 'Folder' => $only->folder];

        return ($this->executeScript)($script, $device, $user, parameters: ($this->validateParameters)($script, $values, 'quarantine'));
    }
}
