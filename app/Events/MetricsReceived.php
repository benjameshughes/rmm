<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Foundation\Events\Dispatchable;

final class MetricsReceived
{
    use Dispatchable;

    public function __construct(
        public readonly Device $device,
        public readonly DeviceMetric $metric,
    ) {}
}
