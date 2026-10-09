<?php

declare(strict_types=1);

namespace App\DTOs\Backups;

use App\Models\Device;
use App\Models\ServerBackupJob;
use Carbon\CarbonInterface;

/**
 * One backup on the fleet Backups page: a server's backup job, or a PC's
 * profile backups. Status comes from ServerBackupHealth or BackupState, so
 * this page agrees with the Backups tab.
 */
final readonly class BackupOverviewRow
{
    public function __construct(
        public string $key,
        public Device $device,
        public string $name,
        public string $kind,
        public string $statusValue,
        public string $statusLabel,
        public string $statusColor,
        public string $statusIcon,
        public int $rank,
        public bool $needsAttention,
        public ?string $problem,
        public ?CarbonInterface $lastBackupAt,
        public ?string $size,
    ) {}

    public static function forServerJob(ServerBackupJob $job): self
    {
        $health = $job->health();

        return new self(
            key: "server-{$job->id}",
            device: $job->device,
            name: $job->job,
            kind: 'Server',
            statusValue: $health->value,
            statusLabel: $health->label(),
            statusColor: $health->color(),
            statusIcon: $health->icon(),
            rank: $health->rank(),
            needsAttention: $health->needsAttention(),
            problem: $job->problem(),
            lastBackupAt: $job->lastBackupAt(),
            size: $job->latestSizeForHumans(),
        );
    }

    public static function forPc(Device $device): self
    {
        $state = $device->backupState();

        return new self(
            key: "pc-{$device->id}",
            device: $device,
            name: 'User profiles',
            kind: 'PC',
            statusValue: $state->value,
            statusLabel: $state->label(),
            statusColor: $state->color(),
            statusIcon: $state->icon(),
            rank: $state->rank(),
            needsAttention: $state->needsAttention(),
            problem: $device->backupProblem(),
            lastBackupAt: $device->last_good_backup_at,
            size: $device->latestBackupSnapshot?->sizeForHumans(),
        );
    }

    /**
     * "3 hours ago", or "never" before the first backup.
     */
    public function lastBackupForHumans(): string
    {
        return $this->lastBackupAt?->diffForHumans() ?? 'never';
    }
}
