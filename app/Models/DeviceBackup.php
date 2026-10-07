<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupRunStatus;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

/**
 * One run of the backup-files script on a device, as its result line reported it.
 */
final class DeviceBackup extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceBackupFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id',
        'device_command_id',
        'status',
        'exit_code',
        'snapshot_id',
        'files_new',
        'files_changed',
        'files_unmodified',
        'data_added',
        'total_bytes_processed',
        'duration_seconds',
        'errors',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BackupRunStatus::class,
            'exit_code' => 'integer',
            'files_new' => 'integer',
            'files_changed' => 'integer',
            'files_unmodified' => 'integer',
            'data_added' => 'integer',
            'total_bytes_processed' => 'integer',
            'duration_seconds' => 'float',
            'errors' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class, 'device_command_id');
    }

    public function shortSnapshotId(): ?string
    {
        return $this->snapshot_id === null ? null : substr($this->snapshot_id, 0, 8);
    }

    public function dataAddedForHumans(): ?string
    {
        return $this->data_added === null ? null : Number::fileSize($this->data_added, precision: 1);
    }

    public function sizeForHumans(): ?string
    {
        return $this->total_bytes_processed === null ? null : Number::fileSize($this->total_bytes_processed, precision: 1);
    }

    public function durationForHumans(): ?string
    {
        return $this->duration_seconds === null ? null : CarbonInterval::seconds((int) round($this->duration_seconds))->cascade()->forHumans(short: true, parts: 2);
    }

    /**
     * "12 new, 3 changed" for the history table, or null when restic sent no counts.
     */
    public function filesForHumans(): ?string
    {
        return $this->files_new === null ? null : "{$this->files_new} new, {$this->files_changed} changed";
    }
}
