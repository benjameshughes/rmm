<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\BackupRunStatus;
use App\Enums\BackupState;
use App\Enums\ScriptPlatform;
use App\Models\DeviceBackup;
use App\Models\DeviceBackupSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Windows PCs back their user profiles up with restic once their backup
 * credentials are set. The device keeps when its last run finished, how it
 * ended and when it last saved a good snapshot, so the state below needs no
 * query. Until the first good backup, the moment the credentials were set
 * stands in for it, so a PC that never manages one still turns overdue.
 */
trait BacksUpFiles
{
    public function backups(): HasMany
    {
        return $this->hasMany(DeviceBackup::class);
    }

    public function backupSnapshots(): HasMany
    {
        return $this->hasMany(DeviceBackupSnapshot::class);
    }

    public function scopeWithBackupCredentials(Builder $query): Builder
    {
        return $query->whereNotNull('backup_configured_at');
    }

    public function backupState(): BackupState
    {
        return match (true) {
            ! $this->hasBackupCredentials => BackupState::NotConfigured,
            $this->last_backup_status === BackupRunStatus::Failed => BackupState::Failed,
            $this->backupDueSince()->lessThan(now()->subHours(config('backup.stale_after_hours'))) => BackupState::Stale,
            $this->last_good_backup_at === null => BackupState::NeverBackedUp,
            $this->last_backup_status === BackupRunStatus::Partial => BackupState::Partial,
            default => BackupState::Healthy,
        };
    }

    /**
     * Why the backup alert is raised, or null when the PC needs no attention.
     */
    public function backupProblem(): ?string
    {
        return match ($this->backupState()) {
            BackupState::Failed => "last backup failed {$this->last_backup_at?->diffForHumans()}",
            BackupState::Stale => $this->last_good_backup_at === null
                ? "no backup since its credentials were set {$this->backup_configured_at->diffForHumans()}"
                : "last good backup {$this->last_good_backup_at->diffForHumans()}",
            default => null,
        };
    }

    /**
     * Short words for a badge: "Failed", "Some files skipped", or for an
     * overdue PC how old its last good backup is.
     */
    public function backupBadgeLabel(BackupState $state): string
    {
        return $state === BackupState::Stale
            ? ($this->last_good_backup_at === null ? 'none yet' : $this->last_good_backup_at->diffForHumans(short: true))
            : $state->label();
    }

    /**
     * "3 hours ago", or null before the first good backup.
     */
    public function lastGoodBackupForHumans(): ?string
    {
        return $this->last_good_backup_at?->diffForHumans();
    }

    /**
     * Whether this PC can back up, or have its backups restored onto another PC.
     */
    public function canBackUp(): bool
    {
        return $this->hasBackupCredentials && $this->platform() === ScriptPlatform::Windows;
    }

    /**
     * What overdue counts from: the last good backup, or before the first, when the credentials were set.
     */
    private function backupDueSince(): CarbonInterface
    {
        return $this->last_good_backup_at ?? $this->backup_configured_at;
    }

    protected function hasBackupCredentials(): Attribute
    {
        return Attribute::get(fn (): bool => $this->backup_configured_at !== null);
    }
}
