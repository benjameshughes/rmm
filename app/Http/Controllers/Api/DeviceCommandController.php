<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Script\ResolveCommandSecrets;
use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class DeviceCommandController
{
    /**
     * A monitor-only device is never handed a command, whatever its agent claims to support.
     * Secrets the script declares join its parameters here and only here, so they reach the
     * agent without ever being stored or logged.
     */
    public function pending(Request $request, ResolveCommandSecrets $resolveSecrets): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        if ($device->isMonitorOnly) {
            return response()->json(['command' => null]);
        }

        $command = DeviceCommand::query()
            ->where('device_id', $device->id)
            ->where('status', CommandStatus::Pending)
            ->orderBy('queued_at', 'asc')
            ->first();

        if ($command === null || ! $command->markAsSent()) {
            return response()->json(['command' => null]);
        }

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
                'parameters' => (object) [...($command->parameters ?? []), ...$resolveSecrets($command)],
            ],
        ]);
    }

    public function started(Request $request, int $commandId): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $command = DeviceCommand::query()
            ->where('id', $commandId)
            ->where('device_id', $device->id)
            ->first();

        if ($command === null) {
            return response()->json(['message' => 'Command not found'], 404);
        }

        if ($command->status->isTerminal()) {
            return response()->json(['message' => 'Command is already finished'], 409);
        }

        $command->markAsRunning();

        Log::info('api.command.started', [
            'device_id' => $device->id,
            'command_id' => $command->id,
        ]);

        return response()->json(['message' => 'OK']);
    }

    public function result(Request $request, int $commandId): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $command = DeviceCommand::query()
            ->where('id', $commandId)
            ->where('device_id', $device->id)
            ->first();

        if ($command === null) {
            return response()->json(['message' => 'Command not found'], 404);
        }

        if ($command->status->isTerminal()) {
            return response()->json(['message' => 'Command is already finished'], 409);
        }

        $validated = $request->validate([
            'exit_code' => 'required|integer',
            'output' => 'nullable|string|max:1000000',
            'error_message' => 'nullable|string|max:10000',
            'timed_out' => 'sometimes|boolean',
        ]);

        $exitCode = (int) $validated['exit_code'];
        $output = $validated['output'] ?? '';

        match (true) {
            (bool) ($validated['timed_out'] ?? false) => $command->markAsTimedOut($output, $exitCode),
            ! empty($validated['error_message']) => $command->markAsFailed($validated['error_message'], $output, $exitCode),
            default => $command->markAsCompleted($output, $exitCode),
        };

        Log::info('api.command.completed', [
            'device_id' => $device->id,
            'command_id' => $command->id,
            'exit_code' => $exitCode,
            'status' => $command->fresh()->status,
        ]);

        return response()->json(['message' => 'OK']);
    }
}
