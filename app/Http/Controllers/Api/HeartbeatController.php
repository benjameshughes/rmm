<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HeartbeatController
{
    public function store(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $device->forceFill($device->checkInAttributes($request->ip()))->save();

        return response()->json([
            'status' => 'ok',
            'server_time' => now()->toIso8601String(),
            'heartbeat_interval_seconds' => config('devices.heartbeat.interval_seconds'),
        ]);
    }
}
