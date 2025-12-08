<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceMetric extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceMetricFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id',
        'cpu',
        'ram',
        'load1',
        'load5',
        'load15',
        'uptime_seconds',
        'memory_used_mib',
        'memory_free_mib',
        'memory_total_mib',
        'alerts_normal',
        'alerts_warning',
        'alerts_critical',
        'agent_version',
        'payload',
        'recorded_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
