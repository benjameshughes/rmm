<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('returns null when no pending commands', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
    ]);

    $response = $this->withHeaders(['X-Agent-Key' => 'VALID-KEY-123'])
        ->getJson('/api/commands/pending');

    $response->assertSuccessful();
    $response->assertJson(['command' => null]);
});

it('returns pending command and marks as sent', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
    ]);

    $user = User::factory()->create();

    $command = DeviceCommand::create([
        'device_id' => $device->id,
        'script_content' => 'Get-Process',
        'script_type' => 'powershell',
        'status' => DeviceCommand::STATUS_PENDING,
        'queued_at' => now(),
        'queued_by' => $user->id,
        'timeout_seconds' => 300,
    ]);

    $response = $this->withHeaders(['X-Agent-Key' => 'VALID-KEY-123'])
        ->getJson('/api/commands/pending');

    $response->assertSuccessful();
    $response->assertJsonPath('command.id', $command->id);
    $response->assertJsonPath('command.script_content', 'Get-Process');
    $response->assertJsonPath('command.script_type', 'powershell');

    // Command should now be marked as sent
    $command->refresh();
    expect($command->status)->toBe(DeviceCommand::STATUS_SENT);
    expect($command->sent_at)->not->toBeNull();
});

it('returns oldest pending command first', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
    ]);

    $user = User::factory()->create();

    $older = DeviceCommand::create([
        'device_id' => $device->id,
        'script_content' => 'First',
        'script_type' => 'powershell',
        'status' => DeviceCommand::STATUS_PENDING,
        'queued_at' => now()->subMinutes(5),
        'queued_by' => $user->id,
        'timeout_seconds' => 300,
    ]);

    DeviceCommand::create([
        'device_id' => $device->id,
        'script_content' => 'Second',
        'script_type' => 'powershell',
        'status' => DeviceCommand::STATUS_PENDING,
        'queued_at' => now(),
        'queued_by' => $user->id,
        'timeout_seconds' => 300,
    ]);

    $response = $this->withHeaders(['X-Agent-Key' => 'VALID-KEY-123'])
        ->getJson('/api/commands/pending');

    $response->assertJsonPath('command.id', $older->id);
    $response->assertJsonPath('command.script_content', 'First');
});

it('can report command started', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
    ]);

    $user = User::factory()->create();

    $command = DeviceCommand::create([
        'device_id' => $device->id,
        'script_content' => 'Get-Process',
        'script_type' => 'powershell',
        'status' => DeviceCommand::STATUS_SENT,
        'queued_at' => now(),
        'queued_by' => $user->id,
        'timeout_seconds' => 300,
    ]);

    $response = $this->withHeaders(['X-Agent-Key' => 'VALID-KEY-123'])
        ->postJson("/api/commands/{$command->id}/started");

    $response->assertSuccessful();

    $command->refresh();
    expect($command->status)->toBe(DeviceCommand::STATUS_RUNNING);
    expect($command->started_at)->not->toBeNull();
});

it('can report command result success', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
    ]);

    $user = User::factory()->create();

    $command = DeviceCommand::create([
        'device_id' => $device->id,
        'script_content' => 'Get-Process',
        'script_type' => 'powershell',
        'status' => DeviceCommand::STATUS_RUNNING,
        'queued_at' => now(),
        'queued_by' => $user->id,
        'timeout_seconds' => 300,
    ]);

    $response = $this->withHeaders(['X-Agent-Key' => 'VALID-KEY-123'])
        ->postJson("/api/commands/{$command->id}/result", [
            'exit_code' => 0,
            'output' => 'Process list here...',
        ]);

    $response->assertSuccessful();

    $command->refresh();
    expect($command->status)->toBe(DeviceCommand::STATUS_COMPLETED);
    expect($command->exit_code)->toBe(0);
    expect($command->output)->toBe('Process list here...');
    expect($command->completed_at)->not->toBeNull();
});

it('can report command result failure', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
    ]);

    $user = User::factory()->create();

    $command = DeviceCommand::create([
        'device_id' => $device->id,
        'script_content' => 'Invalid-Command',
        'script_type' => 'powershell',
        'status' => DeviceCommand::STATUS_RUNNING,
        'queued_at' => now(),
        'queued_by' => $user->id,
        'timeout_seconds' => 300,
    ]);

    $response = $this->withHeaders(['X-Agent-Key' => 'VALID-KEY-123'])
        ->postJson("/api/commands/{$command->id}/result", [
            'exit_code' => 1,
            'output' => '',
            'error_message' => 'Command not found',
        ]);

    $response->assertSuccessful();

    $command->refresh();
    expect($command->status)->toBe(DeviceCommand::STATUS_FAILED);
    expect($command->exit_code)->toBe(1);
    expect($command->error_message)->toBe('Command not found');
});

it('rejects commands without valid api key', function (): void {
    $response = $this->getJson('/api/commands/pending');
    $response->assertUnauthorized();

    $response = $this->withHeaders(['X-Agent-Key' => 'INVALID'])
        ->getJson('/api/commands/pending');
    $response->assertUnauthorized();
});

it('cannot access other device commands', function (): void {
    $device1 = Device::factory()->active()->create([
        'api_key' => 'DEVICE-1-KEY',
    ]);

    $device2 = Device::factory()->active()->create([
        'api_key' => 'DEVICE-2-KEY',
    ]);

    $user = User::factory()->create();

    $command = DeviceCommand::create([
        'device_id' => $device1->id,
        'script_content' => 'Get-Process',
        'script_type' => 'powershell',
        'status' => DeviceCommand::STATUS_RUNNING,
        'queued_at' => now(),
        'queued_by' => $user->id,
        'timeout_seconds' => 300,
    ]);

    // Device 2 should not be able to report results for Device 1's command
    $response = $this->withHeaders(['X-Agent-Key' => 'DEVICE-2-KEY'])
        ->postJson("/api/commands/{$command->id}/result", [
            'exit_code' => 0,
            'output' => 'Hacked!',
        ]);

    $response->assertNotFound();
});
