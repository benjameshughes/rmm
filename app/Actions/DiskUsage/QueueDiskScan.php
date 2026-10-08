<?php

declare(strict_types=1);

namespace App\Actions\DiskUsage;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\ScriptPlatform;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Queues the disk-usage script on a Windows PC for one drive or folder,
 * unless a scan is already queued or running there. Returns null when one is.
 */
final class QueueDiskScan
{
    public function __construct(
        private readonly ExecuteScriptOnDevice $executeScript,
        private readonly ValidateScriptParameterValues $validateParameters,
    ) {}

    /**
     * @throws ValidationException When the device is not a Windows PC, or the folder or depth is not one the script takes
     */
    public function __invoke(Device $device, User $user, string $path, ?int $depth = null, string $attribute = 'scan'): ?DeviceCommand
    {
        $depth ??= config('disk_usage.default_depth');

        throw_unless(
            $device->platform() === ScriptPlatform::Windows,
            ValidationException::withMessages([$attribute => 'Disk scans run on Windows PCs only.']),
        );

        throw_unless(
            preg_match(config('disk_usage.path_pattern'), $path) === 1,
            ValidationException::withMessages([$attribute => "{$path} is not a drive or folder such as C:\\ or C:\\Users."]),
        );

        throw_unless(
            $depth >= config('disk_usage.min_depth') && $depth <= config('disk_usage.max_depth'),
            ValidationException::withMessages([$attribute => 'Scan from '.config('disk_usage.min_depth').' to '.config('disk_usage.max_depth').' folder levels deep.']),
        );

        $script = Script::findSystem(config('disk_usage.slug'));

        if ($device->inFlightCommands()->where('script_id', $script->id)->exists()) {
            return null;
        }

        $parameters = ($this->validateParameters)($script, ['Path' => $path, 'Depth' => (string) $depth], $attribute);

        return ($this->executeScript)($script, $device, $user, parameters: $parameters);
    }
}
