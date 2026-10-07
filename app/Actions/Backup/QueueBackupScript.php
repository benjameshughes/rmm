<?php

declare(strict_types=1);

namespace App\Actions\Backup;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\BackupScript;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;

/**
 * Queues one of the backup scripts on a device from the Backups tab. Values
 * go through the script's own parameter rules, so they read as if typed into
 * Run Script; credentials never travel this way.
 */
final class QueueBackupScript
{
    public function __construct(
        private readonly ExecuteScriptOnDevice $executeScript,
        private readonly ValidateScriptParameterValues $validateParameters,
    ) {}

    /**
     * @param  array<string, string|int|null>  $values  Keyed by parameter name; nulls are left out
     */
    public function __invoke(BackupScript $backupScript, Device $device, User $user, array $values = [], string $attribute = 'backup'): DeviceCommand
    {
        $script = Script::findSystem($backupScript->value);
        $values = collect($values)->reject(fn (string|int|null $value): bool => $value === null || $value === '')->all();

        return ($this->executeScript)($script, $device, $user, parameters: ($this->validateParameters)($script, $values, $attribute));
    }
}
