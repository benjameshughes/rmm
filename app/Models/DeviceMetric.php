<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DeviceMetric extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceMetricFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id',
        'cpu',
        'cpu_user',
        'cpu_system',
        'cpu_nice',
        'cpu_iowait',
        'cpu_irq',
        'cpu_softirq',
        'cpu_steal',
        'cpu_idle',
        'cpu_cores',
        'cpu_frequency_mhz',
        'ram',
        'load1',
        'load5',
        'load15',
        'uptime_seconds',
        'memory_used_mib',
        'memory_free_mib',
        'memory_total_mib',
        'memory_cached_mib',
        'memory_buffers_mib',
        'memory_available_mib',
        'alerts_normal',
        'alerts_warning',
        'alerts_critical',
        'agent_version',
        'processes_running',
        'processes_blocked',
        'processes_total',
        'payload',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'cpu' => 'float',
            'ram' => 'float',
            'payload' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function diskMetrics(): HasMany
    {
        return $this->hasMany(DeviceDiskMetric::class);
    }

    public function networkMetrics(): HasMany
    {
        return $this->hasMany(DeviceNetworkMetric::class);
    }

    public function uptimeForHumans(): ?string
    {
        if ($this->uptime_seconds === null) {
            return null;
        }

        return CarbonInterval::seconds((int) $this->uptime_seconds)
            ->cascade()
            ->forHumans(['short' => true, 'parts' => 2, 'minimumUnit' => 'minute']);
    }
}
