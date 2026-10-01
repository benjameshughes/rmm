<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DeviceStatus;
use App\Http\Requests\CheckRequest;
use App\Http\Requests\EnrollRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class DeviceEnrollmentController
{
    public function store(EnrollRequest $request): JsonResponse
    {
        $data = $request->validated();
        $fingerprint = $data['hardware_fingerprint'] ?? null;
        $device = $this->findByFingerprint($fingerprint);

        $descriptiveFields = [
            'os' => $data['os'] ?? null,
            'os_name' => $data['os_name'] ?? null,
            'os_version' => $data['os_version'] ?? null,
            'cpu_model' => $data['cpu_model'] ?? null,
            'cpu_cores' => $data['cpu_cores'] ?? null,
            'total_ram_gb' => $data['total_ram_gb'] ?? null,
            'disks' => $data['disks'] ?? null,
        ];

        if ($device === null) {
            $device = Device::create([
                ...$descriptiveFields,
                'hostname' => $data['hostname'],
                'hardware_fingerprint' => $fingerprint,
                'status' => DeviceStatus::Pending,
                'last_ip' => $request->ip(),
            ]);
            $outcome = 'created';
        } elseif ($device->status === DeviceStatus::Pending) {
            $device->fill([
                ...array_filter($descriptiveFields, fn (mixed $value): bool => $value !== null),
                'last_ip' => $request->ip(),
            ])->save();
            $outcome = 'refreshed';
        } else {
            $outcome = 'ignored';
        }

        Log::info('api.enroll', [
            'device_id' => $device->id,
            'hostname' => $data['hostname'],
            'fingerprint_prefix' => $this->fingerprintPrefix($fingerprint),
            'status' => $device->status,
            'outcome' => $outcome,
            'ip' => $request->ip(),
        ]);

        if ($device->status === DeviceStatus::Revoked) {
            return response()->json([
                ...$this->statusPayload($device),
                'message' => 'Device enrollment has been revoked.',
            ], 403);
        }

        return response()->json($this->statusPayload($device));
    }

    public function check(CheckRequest $request): JsonResponse
    {
        $data = $request->validated();
        $fingerprint = $data['hardware_fingerprint'] ?? null;
        $device = $this->findByFingerprint($fingerprint);

        if ($device === null) {
            Log::info('api.check', [
                'matched' => false,
                'hostname' => $data['hostname'] ?? null,
                'fingerprint_prefix' => $this->fingerprintPrefix($fingerprint),
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => DeviceStatus::Pending->value]);
        }

        $response = $this->statusPayload($device);
        $apiKey = $device->claimPendingApiKey();

        if ($apiKey !== null) {
            $response['api_key'] = $apiKey;
        }

        Log::info('api.check', [
            'matched' => true,
            'device_id' => $device->id,
            'hostname' => $device->hostname,
            'fingerprint_prefix' => $this->fingerprintPrefix($fingerprint),
            'status' => $device->status,
            'key_delivered' => $apiKey !== null,
            'ip' => $request->ip(),
        ]);

        return response()->json($response);
    }

    private function findByFingerprint(?string $fingerprint): ?Device
    {
        if ($fingerprint === null || $fingerprint === '') {
            return null;
        }

        return Device::query()->where('hardware_fingerprint', $fingerprint)->first();
    }

    /**
     * @return array{status: string, device_status: string}
     */
    private function statusPayload(Device $device): array
    {
        return [
            'status' => $device->status->agentStatus(),
            'device_status' => $device->status->value,
        ];
    }

    private function fingerprintPrefix(?string $fingerprint): ?string
    {
        return $fingerprint === null ? null : mb_substr($fingerprint, 0, 8);
    }
}
