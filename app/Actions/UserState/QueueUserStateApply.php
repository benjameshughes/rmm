<?php

declare(strict_types=1);

namespace App\Actions\UserState;

use App\Enums\DeviceStatus;
use App\Enums\UserStateScript;
use App\Models\Device;
use App\Models\DeviceCommand;

/**
 * The second half of Migrate User from another PC: once a capture run
 * succeeds, queues apply-user-state on the PC named by its ApplyDevice
 * parameter, for its ApplyUsername, with the snapshot and captured user
 * from the capture's JSON result and the capture PC as SourceDevice. The
 * apply command carries the capture's ID as CaptureCommand, which links the
 * two and stops a second apply being queued for the same capture.
 *
 * A capture that failed, has no follow-up, reported no snapshot, or whose
 * target PC no longer takes commands queues nothing.
 */
final class QueueUserStateApply
{
    public function __construct(
        private readonly QueueUserStateScript $queue,
    ) {}

    public function __invoke(DeviceCommand $capture): ?DeviceCommand
    {
        $result = $capture->resultJson() ?? [];
        $snapshotId = (string) ($result['snapshot_id'] ?? '');
        $fromUser = (string) ($result['from_user'] ?? '');
        $username = (string) ($capture->parameters['ApplyUsername'] ?? '');
        $target = $this->target($capture);

        if (! $capture->isSuccessful() || $target === null || $username === '' || $capture->queuedBy === null) {
            return null;
        }

        if (preg_match('/^[0-9a-f]{64}$/', $snapshotId) !== 1 || ! str_contains($fromUser, '\\') || $this->alreadyQueued($capture)) {
            return null;
        }

        return ($this->queue)(UserStateScript::Apply, $target, $capture->queuedBy, [
            'SnapshotId' => $snapshotId,
            'SourceDevice' => $capture->device_id,
            'FromUser' => $fromUser,
            'Username' => $username,
            'CaptureCommand' => $capture->id,
        ]);
    }

    private function target(DeviceCommand $capture): ?Device
    {
        $targetId = $capture->parameters['ApplyDevice'] ?? null;

        return $targetId === null ? null : Device::query()
            ->where('status', DeviceStatus::Active)
            ->runsWindows()
            ->acceptsCommands()
            ->find((int) $targetId);
    }

    private function alreadyQueued(DeviceCommand $capture): bool
    {
        return DeviceCommand::query()
            ->whereRelation('script', 'slug', UserStateScript::Apply->value)
            ->where('parameters->CaptureCommand', (string) $capture->id)
            ->exists();
    }
}
