<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceDiskMetric extends Model
{
    protected $fillable = [
        'device_metric_id',
        'mount_point',
        'filesystem',
        'used_gb',
        'available_gb',
        'total_gb',
        'usage_percent',
        'read_kbps',
        'write_kbps',
        'utilization_percent',
        'inode_usage_percent',
    ];

    public function deviceMetric(): BelongsTo
    {
        return $this->belongsTo(DeviceMetric::class);
    }
}
