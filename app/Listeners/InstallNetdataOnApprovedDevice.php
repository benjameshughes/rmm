<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Enums\ScriptPlatform;
use App\Events\DeviceApproved;
use App\Models\Script;

/**
 * The agent installer skips Netdata so enrolment takes seconds, and the agent
 * posts blank metrics until it appears. Queue the install the moment the PC
 * is approved so the charts fill in without anyone remembering to.
 */
final class InstallNetdataOnApprovedDevice
{
    public function __construct(
        private readonly ExecuteScriptOnDevice $executeScript,
    ) {}

    public function handle(DeviceApproved $event): void
    {
        $script = Script::query()->system()->where('slug', config('devices.metrics.netdata_install_script_slug'))->first();

        if ($script === null || $event->device->platform() !== ScriptPlatform::Windows || $event->device->isMonitorOnly) {
            return;
        }

        ($this->executeScript)($script, $event->device, $event->approvedBy);
    }
}
