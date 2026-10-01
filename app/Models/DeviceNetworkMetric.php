<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceNetworkMetric extends Model
{
    protected $fillable = [
        'device_metric_id',
        'interface',
        'received_kbps',
        'sent_kbps',
        'received_bytes',
        'sent_bytes',
    ];

    public function deviceMetric(): BelongsTo
    {
        return $this->belongsTo(DeviceMetric::class);
    }
}
