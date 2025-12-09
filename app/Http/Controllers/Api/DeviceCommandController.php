<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class DeviceCommandController extends Controller
{
    /**
     * Get pending commands for the authenticated device.
     * Agent polls this endpoint to check for work.
     */
    public function pending(Request $request): JsonResponse
    {
        $device = $this->authenticateDevice($request);

        if ($device === null) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Get the oldest pending command for this device
        $command = DeviceCommand::query()
            ->where('device_id', $device->id)
            ->where('status', DeviceCommand::STATUS_PENDING)
            ->orderBy('queued_at', 'asc')
            ->first();

        if ($command === null) {
            return response()->json(['command' => null]);
        }

        // Mark as sent
        $command->markAsSent();

        Log::info('api.command.sent', [
            'device_id' => $device->id,
            'command_id' => $command->id,
            'script_type' => $command->script_type,
        ]);

        return response()->json([
            'command' => [
                'id' => $command->id,
                'script_content' => $command->script_content,
                'script_type' => $command->script_type,
                'timeout_seconds' => $command->timeout_seconds,
            ],
        ]);
    }

    /**
     * Agent reports that command execution has started.
     */
    public function started(Request $request, int $commandId): JsonResponse
    {
        $device = $this->authenticateDevice($request);

        if ($device === null) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $command = DeviceCommand::query()
            ->where('id', $commandId)
            ->where('device_id', $device->id)
            ->first();

        if ($command === null) {
            return response()->json(['message' => 'Command not found'], 404);
        }

        $command->markAsRunning();

        Log::info('api.command.started', [
            'device_id' => $device->id,
            'command_id' => $command->id,
        ]);

        return response()->json(['message' => 'OK']);
    }

    /**
     * Agent reports command result.
     */
    public function result(Request $request, int $commandId): JsonResponse
    {
        $device = $this->authenticateDevice($request);

        if ($device === null) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $command = DeviceCommand::query()
            ->where('id', $commandId)
            ->where('device_id', $device->id)
            ->first();

        if ($command === null) {
            return response()->json(['message' => 'Command not found'], 404);
        }

        $validated = $request->validate([
            'exit_code' => 'required|integer',
            'output' => 'nullable|string|max:1000000',
            'error_message' => 'nullable|string|max:10000',
        ]);

        $exitCode = (int) $validated['exit_code'];
        $output = $validated['output'] ?? '';

        if (isset($validated['error_message']) && $validated['error_message']) {
            $command->markAsFailed($validated['error_message'], $output, $exitCode);
        } else {
            $command->markAsCompleted($output, $exitCode);
        }

        Log::info('api.command.completed', [
            'device_id' => $device->id,
            'command_id' => $command->id,
            'exit_code' => $exitCode,
            'status' => $command->status,
        ]);

        return response()->json(['message' => 'OK']);
    }

    /**
     * Authenticate device from API key header.
     */
    private function authenticateDevice(Request $request): ?Device
    {
        $apiKey = $request->header('X-Agent-Key') ?? $request->header('X-Device-Key');

        if (! $apiKey) {
            return null;
        }

        return Device::query()
            ->where('api_key', trim($apiKey))
            ->where('status', Device::STATUS_ACTIVE)
            ->first();
    }
}
