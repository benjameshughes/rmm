<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Device\RecordDevicePowerEvent;
use App\Http\Requests\PowerEventRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

final class PowerEventController
{
    public function store(PowerEventRequest $request, RecordDevicePowerEvent $recordDevicePowerEvent): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $recordDevicePowerEvent($device, $request->powerState(), $request->reason(), $request->ip());

        return response()->json([
            'status' => 'ok',
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
