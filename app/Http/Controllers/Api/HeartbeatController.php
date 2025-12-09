<?php

namespace App\Http\Controllers\Api;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class HeartbeatController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $apiKey = $request->header('X-Device-Key') ?? $request->header('X-Agent-Key');
        if (is_string($apiKey)) {
            $apiKey = trim($apiKey);
        }

        if (! $apiKey) {
            Log::debug('api.heartbeat.unauthorized', [
                'reason' => 'missing_key',
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Missing device key.'], 401);
        }

        $device = Device::query()
            ->where('api_key', $apiKey)
            ->where('status', Device::STATUS_ACTIVE)
            ->first();

        if ($device === null) {
            Log::debug('api.heartbeat.unauthorized', [
                'reason' => 'invalid_or_revoked',
                'key_prefix' => is_string($apiKey) ? substr($apiKey, 0, 8) : null,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid or revoked device key.'], 401);
        }

        // Minimal update - only last_seen and last_ip
        $device->forceFill([
            'last_seen' => now(),
            'last_ip' => $request->ip(),
        ])->save();

        Log::debug('api.heartbeat', [
            'device_id' => $device->id,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'status' => 'ok',
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
