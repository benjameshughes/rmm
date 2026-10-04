<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetricSampleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One per-second point from a device's Netdata window, stamped with the
 * device's own clock as Netdata recorded it.
 */
final class MetricSample extends Model
{
    /** @use HasFactory<\Database\Factories\MetricSampleFactory> */
    use HasFactory;

    use MassPrunable;

    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'metric',
        'recorded_at',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'metric' => MetricSampleType::class,
            'recorded_at' => 'datetime',
            'value' => 'float',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('recorded_at', '<', now()->subHours((int) config('devices.metrics.sample_retention_hours')));
    }
}
