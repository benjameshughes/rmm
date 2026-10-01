<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\FormatsMebibytes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceAppMetric extends Model
{
    use FormatsMebibytes;

    /** @use HasFactory<\Database\Factories\DeviceAppMetricFactory> */
    use HasFactory;

    use MassPrunable;

    protected $fillable = [
        'device_metric_id',
        'name',
        'cpu_percent',
        'memory_mib',
    ];

    protected function casts(): array
    {
        return [
            'cpu_percent' => 'float',
            'memory_mib' => 'float',
        ];
    }

    public function deviceMetric(): BelongsTo
    {
        return $this->belongsTo(DeviceMetric::class);
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subHours((int) config('devices.metrics.app_history_hours')));
    }

    public function cpuForHumans(): ?string
    {
        return $this->cpu_percent === null ? null : number_format($this->cpu_percent, 1).'%';
    }

    public function memoryForHumans(): ?string
    {
        return $this->memory_mib === null ? null : $this->mebibytesForHumans($this->memory_mib);
    }
}
