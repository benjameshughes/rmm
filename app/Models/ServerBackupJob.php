<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServerBackupHealth;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Number;

/**
 * One backup job on a Linux server, as its status file last described it.
 * The latest snapshot's time and size, and the baseline it is compared with,
 * are kept on the row when snapshots are stored, so health() needs no query.
 */
final class ServerBackupJob extends Model
{
    /** @use HasFactory<\Database\Factories\ServerBackupJobFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id',
        'job',
        'tool',
        'repository',
        'last_exit_code',
        'started_at',
        'finished_at',
        'status_file_modified_at',
        'snapshot_count',
        'total_size',
        'total_uncompressed_size',
        'compression_ratio',
        'compression_space_saving',
        'total_blob_count',
        'latest_snapshot_at',
        'latest_bytes_processed',
        'baseline_bytes_processed',
        'last_error',
        'last_reported_at',
    ];

    protected function casts(): array
    {
        return [
            'last_exit_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status_file_modified_at' => 'datetime',
            'snapshot_count' => 'integer',
            'total_size' => 'integer',
            'total_uncompressed_size' => 'integer',
            'compression_ratio' => 'float',
            'compression_space_saving' => 'float',
            'total_blob_count' => 'integer',
            'latest_snapshot_at' => 'datetime',
            'latest_bytes_processed' => 'integer',
            'baseline_bytes_processed' => 'integer',
            'last_reported_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ServerBackupSnapshot::class);
    }

    public function health(): ServerBackupHealth
    {
        return match (true) {
            $this->last_reported_at->lessThan(now()->subHours(config('backup.servers.forget_missing_after_hours'))) => ServerBackupHealth::Missing,
            $this->last_error !== null => ServerBackupHealth::Unreadable,
            $this->last_exit_code !== null && $this->last_exit_code !== 0 => ServerBackupHealth::Failed,
            $this->dueSince()->lessThan(now()->subMinutes(config('backup.servers.stale_after_minutes'))) => ServerBackupHealth::Overdue,
            $this->hasShrunk() => ServerBackupHealth::Shrunk,
            default => ServerBackupHealth::Healthy,
        };
    }

    /**
     * Why the job needs attention, or null when it is healthy.
     */
    public function problem(): ?string
    {
        return match ($this->health()) {
            ServerBackupHealth::Missing => "status file not reported since {$this->last_reported_at->diffForHumans()}",
            ServerBackupHealth::Unreadable => "status file unreadable: {$this->last_error}",
            ServerBackupHealth::Failed => "last run exited with code {$this->last_exit_code}".($this->finished_at ? ", {$this->finished_at->diffForHumans()}" : ''),
            ServerBackupHealth::Overdue => $this->latest_snapshot_at === null
                ? 'no snapshot yet'
                : "no snapshot since {$this->latest_snapshot_at->diffForHumans()}",
            ServerBackupHealth::Shrunk => "latest snapshot backed up {$this->latestSizeForHumans()}, usually about ".Number::fileSize((int) $this->baseline_bytes_processed, maxPrecision: 1),
            ServerBackupHealth::Healthy => null,
        };
    }

    /**
     * Restic's newest snapshot, or before the first one, when the last run finished.
     */
    public function lastBackupAt(): ?CarbonInterface
    {
        return $this->latest_snapshot_at ?? $this->finished_at;
    }

    public function latestSizeForHumans(): ?string
    {
        return $this->latest_bytes_processed === null ? null : Number::fileSize($this->latest_bytes_processed, maxPrecision: 1);
    }

    public function repositorySizeForHumans(): ?string
    {
        return $this->total_size === null ? null : Number::fileSize($this->total_size, maxPrecision: 1);
    }

    /**
     * "2.1x compression, 52% saved", or null when the agent sent no stats.
     */
    public function compressionForHumans(): ?string
    {
        if ($this->compression_ratio === null) {
            return null;
        }

        $saving = $this->compression_space_saving === null ? '' : ', '.Number::percentage($this->compression_space_saving).' saved';

        return Number::format($this->compression_ratio, maxPrecision: 1).'x compression'.$saving;
    }

    /**
     * "Last run 12 minutes ago, took 4m 2s, exit 0", or null before any run was reported.
     */
    public function lastRunForHumans(): ?string
    {
        if ($this->finished_at === null) {
            return null;
        }

        return collect([
            "Last run {$this->finished_at->diffForHumans()}",
            $this->started_at === null ? null : 'took '.CarbonInterval::seconds((int) max(0, $this->started_at->diffInSeconds($this->finished_at)))->cascade()->forHumans(short: true, parts: 2),
            $this->last_exit_code === null ? null : "exit {$this->last_exit_code}",
        ])->filter()->implode(', ');
    }

    /**
     * "1,606", or null when the agent sent no count.
     */
    public function snapshotCountForHumans(): ?string
    {
        return $this->snapshot_count === null ? null : Number::format($this->snapshot_count);
    }

    /**
     * Shrunk: the newest snapshot processed under the threshold share of the
     * baseline, the median of the snapshots before it that processed anything.
     */
    private function hasShrunk(): bool
    {
        return $this->latest_bytes_processed !== null
            && $this->baseline_bytes_processed !== null
            && $this->baseline_bytes_processed > 0
            && $this->latest_bytes_processed < config('backup.servers.shrink_threshold') * $this->baseline_bytes_processed;
    }

    private function dueSince(): CarbonInterface
    {
        return $this->lastBackupAt() ?? $this->created_at;
    }
}
