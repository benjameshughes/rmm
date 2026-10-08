<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\DiskUsage\DiskScan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One disk-usage run on one device: the full rmm.du/1 JSON plus its headline
 * numbers as columns.
 */
final class DeviceDiskScan extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceDiskScanFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id',
        'root',
        'depth',
        'scanned_at',
        'duration_ms',
        'allocated',
        'files',
        'error_count',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'depth' => 'integer',
            'duration_ms' => 'integer',
            'allocated' => 'integer',
            'files' => 'integer',
            'error_count' => 'integer',
            'data' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Null when the stored JSON is no longer a schema this app reads.
     */
    public function scan(): ?DiskScan
    {
        return DiskScan::fromArray($this->data ?? []);
    }

    public function scopeOfRoot(Builder $query, string $root): Builder
    {
        return $query->where('root', $root);
    }

    public function scannedForHumans(): string
    {
        return 'Scanned '.$this->scanned_at->diffForHumans().'.';
    }
}
