<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Enums\CommandStatus;
use App\Enums\DevicePowerState;
use App\Enums\PowerEventReason;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Support\Facades\Log;

final class RecordDevicePowerEvent
{
    /**
     * A device powering on is talking to us, so it counts as a check-in. One
     * powering off keeps its last_seen: it is going away, not reporting in.
     * Saving the device broadcasts DeviceUpdated, which flips open pages.
     * A shutdown kills whatever the agent was running, so those commands fail
     * now instead of sitting at Running until they time out. Sleep does not:
     * the machine resumes and the command carries on.
     */
    public function __invoke(Device $device, DevicePowerState $powerState, PowerEventReason $reason, ?string $ip = null): void
    {
        $device->forceFill([
            ...($powerState === DevicePowerState::PoweringOn ? $device->checkInAttributes($ip) : []),
            'power_state' => $powerState,
            'power_state_changed_at' => now(),
        ])->save();

        if ($reason === PowerEventReason::Shutdown) {
            $this->settleCommandsCutOffByShutdown($device);
        }

        Log::info('device.power', [
            'device_id' => $device->id,
            'power_state' => $powerState->value,
            'reason' => $reason->value,
        ]);
    }

    /**
     * The built-in restart and shutdown scripts cause the shutdown, so one still
     * running when the notice lands has done its job rather than been cut off.
     */
    private function settleCommandsCutOffByShutdown(Device $device): void
    {
        $device->commands()
            ->with('script')
            ->whereIn('status', [CommandStatus::Sent, CommandStatus::Running])
            ->get()
            ->each(fn (DeviceCommand $command) => $this->causedTheShutdown($command)
                ? $command->markAsCompleted('The device is shutting down or restarting as requested.', 0)
                : $command->markAsFailed('Interrupted: the device shut down or restarted while this was running'));
    }

    private function causedTheShutdown(DeviceCommand $command): bool
    {
        return $command->script?->is_system === true
            && in_array($command->script->slug, config('devices.power.shutdown_script_slugs'), true);
    }
}
