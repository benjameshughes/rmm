<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Enums\ScriptPlatform;
use App\Events\NetdataWentQuiet;
use App\Models\Device;
use App\Models\Script;
use App\Models\User;

/**
 * Queues the Netdata repair script on a Windows PC that has stopped reporting
 * CPU, unless one was already queued within the cooldown. A repair that fails
 * shows as a failed command, which is the escalation.
 */
final class RepairQuietNetdata
{
    public function __construct(
        private readonly ExecuteScriptOnDevice $executeScript,
    ) {}

    public function handle(NetdataWentQuiet $event): void
    {
        $device = $event->device;
        $script = Script::query()->system()->where('slug', config('devices.metrics.netdata_repair_script_slug'))->first();

        if ($script === null || $device->platform() !== ScriptPlatform::Windows || $device->isMonitorOnly) {
            return;
        }

        if ($this->wasRepairedRecently($device, $script)) {
            return;
        }

        $user = $this->queueAs($device);

        if ($user === null) {
            return;
        }

        ($this->executeScript)($script, $device, $user);
    }

    private function wasRepairedRecently(Device $device, Script $script): bool
    {
        return $device->commands()
            ->where('script_id', $script->id)
            ->where('queued_at', '>=', now()->subHours(config('devices.metrics.netdata_repair_cooldown_hours')))
            ->exists();
    }

    /**
     * Nobody asked for this run, so it is queued as whoever last queued a
     * command on the PC (its approver queued Install Netdata), else the first user.
     */
    private function queueAs(Device $device): ?User
    {
        return $device->commands()->with('queuedBy')->latest('queued_at')->first()?->queuedBy
            ?? User::query()->oldest('id')->first();
    }
}
