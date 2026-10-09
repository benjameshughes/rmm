<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

/**
 * One restic snapshot of a server backup job, with restic's own summary of
 * the run that made it (missing on snapshots from old restic versions).
 */
final class ServerBackupSnapshot extends Model
{
    /** @use HasFactory<\Database\Factories\ServerBackupSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'server_backup_job_id',
        'snapshot_id',
        'short_id',
        'taken_at',
        'hostname',
        'paths',
        'tags',
        'duration_seconds',
        'files_new',
        'files_changed',
        'files_unmodified',
        'total_files_processed',
        'total_bytes_processed',
        'data_added',
        'data_added_packed',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'paths' => 'array',
            'tags' => 'array',
            'duration_seconds' => 'float',
            'files_new' => 'integer',
            'files_changed' => 'integer',
            'files_unmodified' => 'integer',
            'total_files_processed' => 'integer',
            'total_bytes_processed' => 'integer',
            'data_added' => 'integer',
            'data_added_packed' => 'integer',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServerBackupJob::class, 'server_backup_job_id');
    }

    public function sizeForHumans(): ?string
    {
        return $this->total_bytes_processed === null ? null : Number::fileSize($this->total_bytes_processed, maxPrecision: 1);
    }

    public function dataAddedForHumans(): ?string
    {
        return $this->data_added === null ? null : Number::fileSize($this->data_added, maxPrecision: 1);
    }

    public function durationForHumans(): ?string
    {
        return $this->duration_seconds === null ? null : CarbonInterval::seconds((int) round($this->duration_seconds))->cascade()->forHumans(short: true, parts: 2);
    }

    /**
     * "12 new, 3 changed", or null when restic sent no summary.
     */
    public function filesForHumans(): ?string
    {
        return $this->files_new === null ? null : "{$this->files_new} new, {$this->files_changed} changed";
    }

    /**
     * Paths then tags, one line: "/all-databases-20261009-0900.sql · nightly".
     */
    public function contentsForHumans(): string
    {
        return collect([implode(', ', $this->paths ?? []), implode(', ', $this->tags ?? [])])
            ->filter()
            ->implode(' · ');
    }
}
