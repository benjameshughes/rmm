<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\CheckRequest;
use App\Http\Requests\EnrollRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class DeviceEnrollmentController extends Controller
{
    public function store(EnrollRequest $request): JsonResponse
    {
        $data = $request->validated();

        $device = Device::query()
            ->when(
                $data['hardware_fingerprint'] ?? null,
                fn ($q) => $q->where('hardware_fingerprint', $data['hardware_fingerprint'])
            )
            ->when(
                ! ($data['hardware_fingerprint'] ?? null),
                fn ($q) => $q->where('hostname', $data['hostname'])
            )
            ->first();

        // If enrolling with a fingerprint that isn't yet attached, try to merge on hostname
        if ($device === null && ($data['hardware_fingerprint'] ?? null) && ($data['hostname'] ?? null)) {
            $device = Device::query()->where('hostname', $data['hostname'])->first();
        }

        if ($device === null) {
            $device = Device::create([
                'hostname' => $data['hostname'],
                'hardware_fingerprint' => $data['hardware_fingerprint'] ?? null,
                'os' => $data['os'] ?? null,
                'os_name' => $data['os_name'] ?? null,
                'os_version' => $data['os_version'] ?? null,
                'cpu_model' => $data['cpu_model'] ?? null,
                'cpu_cores' => $data['cpu_cores'] ?? null,
                'total_ram_gb' => $data['total_ram_gb'] ?? null,
                'disks' => $data['disks'] ?? null,
                'status' => Device::STATUS_PENDING,
                'last_ip' => $request->ip(),
            ]);
        } else {
            // Keep details fresh on re-enroll attempts
            $device->fill([
                'hostname' => $data['hostname'],
                'hardware_fingerprint' => $data['hardware_fingerprint'] ?? $device->hardware_fingerprint,
                'os' => $data['os'] ?? $device->os,
                'os_name' => $data['os_name'] ?? $device->os_name,
                'os_version' => $data['os_version'] ?? $device->os_version,
                'cpu_model' => $data['cpu_model'] ?? $device->cpu_model,
                'cpu_cores' => $data['cpu_cores'] ?? $device->cpu_cores,
                'total_ram_gb' => $data['total_ram_gb'] ?? $device->total_ram_gb,
                'disks' => $data['disks'] ?? $device->disks,
                'last_ip' => $request->ip(),
            ])->save();
        }

        $response = [
            // Preserve internal status and provide external-friendly alias expected by agent
            'status' => $device->status === Device::STATUS_ACTIVE ? 'approved' : $device->status,
            'device_status' => $device->status,
        ];

        if ($device->status === Device::STATUS_ACTIVE && $device->api_key !== null) {
            $response['api_key'] = $device->api_key;
        }

        Log::info('api.enroll', [
            'device_id' => $device->id,
            'hostname' => $device->hostname,
            'fingerprint' => $device->hardware_fingerprint,
            'status' => $device->status,
            'ip' => $request->ip(),
        ]);

        return response()->json($response);
    }

    public function check(CheckRequest $request): JsonResponse
    {
        $data = $request->validated();

        $device = Device::query()
            ->when(
                $data['hardware_fingerprint'] ?? null,
                fn ($q) => $q->where('hardware_fingerprint', $data['hardware_fingerprint'])
            )
            ->when(
                ! ($data['hardware_fingerprint'] ?? null) && ($data['hostname'] ?? null),
                fn ($q) => $q->where('hostname', $data['hostname'])
            )
            ->first();

        // Fallback: if not found by fingerprint, try hostname when provided
        if ($device === null && ($data['hardware_fingerprint'] ?? null) && ($data['hostname'] ?? null)) {
            $device = Device::query()->where('hostname', $data['hostname'])->first();
        }

        if ($device === null) {
            Log::info('api.check', [
                'matched' => false,
                'hostname' => $data['hostname'] ?? null,
                'fingerprint' => $data['hardware_fingerprint'] ?? null,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'status' => Device::STATUS_PENDING,
            ]);
        }

        $response = [
            'status' => $device->status === Device::STATUS_ACTIVE ? 'approved' : $device->status,
            'device_status' => $device->status,
        ];

        if ($device->status === Device::STATUS_ACTIVE && $device->api_key !== null) {
            $response['api_key'] = $device->api_key;
        }

        Log::info('api.check', [
            'device_id' => $device->id,
            'hostname' => $device->hostname,
            'fingerprint' => $device->hardware_fingerprint,
            'status' => $device->status,
            'returned_status' => $response['status'],
            'ip' => $request->ip(),
        ]);

        return response()->json($response);
    }
}
