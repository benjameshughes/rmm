<?php

declare(strict_types=1);

namespace App\Actions\DiskUsage;

use App\Models\DeviceCommand;
use App\Models\User;

/**
 * Queues a fresh scan of the drive a finished command changed, as whoever
 * queued that command, so the Storage tab shows the space that came back.
 */
final class RescanDrive
{
    public function __construct(
        private readonly QueueDiskScan $queueDiskScan,
    ) {}

    public function __invoke(DeviceCommand $command, string $path): void
    {
        if (preg_match('/^[A-Za-z]:/', $path) !== 1) {
            return;
        }

        ($this->queueDiskScan)($command->device, $command->queuedBy ?? User::automation(), strtoupper($path[0]).':\\');
    }
}
