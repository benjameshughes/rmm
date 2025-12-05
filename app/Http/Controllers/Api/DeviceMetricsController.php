<?php

namespace App\Http\Controllers\Api;

use App\DTOs\NetdataCpuMetric;
use App\DTOs\NetdataRamMetric;
use App\Http\Requests\MetricsRequest;
use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class DeviceMetricsController extends Controller
{
    public function store(MetricsRequest $request): JsonResponse
    {
        $apiKey = $request->header('X-Device-Key') ?? $request->header('X-Agent-Key');
        if (is_string($apiKey)) {
            $apiKey = trim($apiKey);
        }
        if (! $apiKey) {
            Log::warning('api.metrics.unauthorized', [
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
            Log::warning('api.metrics.unauthorized', [
                'reason' => 'invalid_or_revoked',
                'key_prefix' => is_string($apiKey) ? substr($apiKey, 0, 8) : null,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid or revoked device key.'], 401);
        }

        $input = $request->all();

        $cpu = (new NetdataCpuMetric($input['cpu'] ?? null))->getUsagePercent();
        $ram = (new NetdataRamMetric($input['ram'] ?? null))->getUsagePercent();

        $recordedAt = $input['recorded_at'] ?? ($input['timestamp'] ?? null);
        $recordedAt = $recordedAt ? \Illuminate\Support\Carbon::parse($recordedAt) : now();

        // Prefer provided payload, otherwise capture all request input except known scalar fields
        $payload = $input['payload'] ?? null;
        if (! is_array($payload)) {
            // Keep the original body as payload so raw CPU/RAM JSON isn't lost
            $payload = $input;
            unset($payload['recorded_at'], $payload['timestamp']);
        }

        DeviceMetric::create([
            'device_id' => $device->id,
            'cpu' => $cpu,
            'ram' => $ram,
            'payload' => $payload,
            'recorded_at' => $recordedAt,
        ]);

        $device->forceFill([
            'last_seen' => now(),
            'last_ip' => $request->ip(),
        ])->save();

        Log::info('api.metrics', [
            'device_id' => $device->id,
            'cpu' => $cpu,
            'ram' => $ram,
            'ip' => $request->ip(),
            'recorded_at' => $recordedAt,
        ]);

        return response()->json(['message' => 'Metrics accepted.']);
    }
}
