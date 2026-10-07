<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

/**
 * One restic snapshot in a device's repository, as the latest snapshot
 * listing (or a backup run since) reported it.
 */
final class DeviceBackupSnapshot extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceBackupSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id',
        'snapshot_id',
        'short_id',
        'taken_at',
        'paths',
        'files',
        'bytes',
        'bytes_added',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'paths' => 'array',
            'files' => 'integer',
            'bytes' => 'integer',
            'bytes_added' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function sizeForHumans(): ?string
    {
        return $this->bytes === null ? null : Number::fileSize($this->bytes, precision: 1);
    }

    /**
     * The profile folders in the snapshot by their last segment: "anna, ben".
     */
    public function profilesForHumans(): string
    {
        return collect($this->paths)
            ->map(fn (string $path): string => str($path)->replace('\\', '/')->afterLast('/')->toString())
            ->implode(', ');
    }
}
