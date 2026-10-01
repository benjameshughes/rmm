<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Device\StoreDeviceMetrics;
use App\Http\Requests\MetricsRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class DeviceMetricsController
{
    public function store(MetricsRequest $request, StoreDeviceMetrics $storeMetrics): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $metric = $storeMetrics($device, $request->all(), $request->ip());

        Log::info('api.metrics', [
            'device_id' => $device->id,
            'cpu' => $metric->cpu,
            'ram' => $metric->ram,
            'load1' => $metric->load1,
            'agent_version' => $metric->agent_version,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Metrics accepted.']);
    }
}
