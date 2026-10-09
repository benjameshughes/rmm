<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Script\RecordCommandProgress;
use App\Actions\Script\ResolveCommandSecrets;
use App\DTOs\CommandProgress;
use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    /**
     * How far a running command has got, posted by the agent every few
     * seconds from the script's PROGRESS: lines. Read leniently: odd fields
     * are dropped rather than refused, because any 4xx makes the agent stop
     * reporting for that command. A report older than the one kept is
     * ignored. 404 and 409 tell the agent to stop quietly.
     */
    public function progress(Request $request, int $commandId, RecordCommandProgress $record): JsonResponse
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

        if (! $command->status->isWithAgent()) {
            return response()->json(['message' => 'Command is not running'], 409);
        }

        $request->validate([
            'progress' => ['required', 'array'],
            'at' => ['nullable', 'date'],
        ], [
            'progress.required' => 'Send the progress object the script printed.',
            'at.date' => 'at must be an RFC 3339 timestamp.',
        ]);

        $recorded = $record(
            command: $command,
            progress: CommandProgress::fromArray($request->input('progress')),
            at: $request->filled('at') ? Date::parse($request->input('at'))->setTimezone(config('app.timezone')) : now(),
        );

        return response()->json(['message' => $recorded ? 'OK' : 'Ignored']);
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
        $output = $this->withoutProgressLines($validated['output'] ?? '');

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

    /**
     * Agents from 0.9.2 strip PROGRESS: lines themselves; an older agent
     * running a script that prints them leaves them in, so they go here.
     */
    private function withoutProgressLines(string $output): string
    {
        $prefix = config('commands.progress.line_prefix');

        if (! str_contains($output, $prefix)) {
            return $output;
        }

        return Str::of($output)
            ->explode("\n")
            ->reject(fn (string $line): bool => str_starts_with(ltrim($line), $prefix))
            ->implode("\n");
    }
}
