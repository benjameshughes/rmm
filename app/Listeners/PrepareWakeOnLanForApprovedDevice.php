<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Enums\ScriptPlatform;
use App\Events\DeviceApproved;
use App\Models\Script;

/**
 * The daily Prepare Wake-on-LAN schedule would reach a new PC up to a day
 * later, leaving its network card on Windows' sleepy defaults for its first
 * night. Queue it the moment the PC is approved instead.
 */
final class PrepareWakeOnLanForApprovedDevice
{
    public function __construct(
        private readonly ExecuteScriptOnDevice $executeScript,
    ) {}

    public function handle(DeviceApproved $event): void
    {
        $script = Script::query()->system()->where('slug', config('devices.wake_on_lan.prepare_script_slug'))->first();

        if ($script === null || $event->device->platform() !== ScriptPlatform::Windows || $event->device->isMonitorOnly) {
            return;
        }

        ($this->executeScript)($script, $event->device, $event->approvedBy);
    }
}
