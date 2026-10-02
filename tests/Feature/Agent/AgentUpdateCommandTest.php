<?php

declare(strict_types=1);

use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->device = Device::factory()->active()->withApiKey('KEY-UPDATE')->create(['agent_version' => '0.6.1']);
    $this->updateScript = Script::factory()->create(['slug' => 'update-agent']);
});

function reportAgentVersion(string $version): void
{
    test()->postJson('/api/metrics', ['cpu' => 10, 'ram' => 20, 'agent_version' => $version], ['X-Agent-Key' => 'KEY-UPDATE'])
        ->assertSuccessful();
}

it('completes an in-flight update command when the device reports a new version', function (CommandStatus $status): void {
    $command = DeviceCommand::factory()->create(['device_id' => $this->device->id, 'script_id' => $this->updateScript->id, 'status' => $status]);

    reportAgentVersion('0.6.2');

    $command->refresh();
    expect($command->status)->toBe(CommandStatus::Completed)
        ->and($command->exit_code)->toBe(0)
        ->and($command->output)->toBe('Agent updated from 0.6.1 to 0.6.2.')
        ->and($command->completed_at)->not->toBeNull();
})->with([
    'sent' => CommandStatus::Sent,
    'running' => CommandStatus::Running,
]);

it('leaves update commands alone while the version stays the same', function (): void {
    $command = DeviceCommand::factory()->create(['device_id' => $this->device->id, 'script_id' => $this->updateScript->id, 'status' => CommandStatus::Running]);

    reportAgentVersion('0.6.1');

    expect($command->fresh()->status)->toBe(CommandStatus::Running);
});

it('does not touch other scripts, other devices or finished update commands', function (): void {
    $otherScript = DeviceCommand::factory()->create(['device_id' => $this->device->id, 'script_id' => Script::factory()->create()->id, 'status' => CommandStatus::Running]);
    $otherDevice = DeviceCommand::factory()->create(['device_id' => Device::factory()->create()->id, 'script_id' => $this->updateScript->id, 'status' => CommandStatus::Running]);
    $timedOut = DeviceCommand::factory()->create(['device_id' => $this->device->id, 'script_id' => $this->updateScript->id, 'status' => CommandStatus::TimedOut]);

    reportAgentVersion('0.6.2');

    expect($otherScript->fresh()->status)->toBe(CommandStatus::Running)
        ->and($otherDevice->fresh()->status)->toBe(CommandStatus::Running)
        ->and($timedOut->fresh()->status)->toBe(CommandStatus::TimedOut);
});

it('says the previous version was unknown when the device never reported one', function (): void {
    $this->device->forceFill(['agent_version' => null])->save();
    $command = DeviceCommand::factory()->create(['device_id' => $this->device->id, 'script_id' => $this->updateScript->id, 'status' => CommandStatus::Running]);

    reportAgentVersion('0.6.2');

    expect($command->fresh()->output)->toBe('Agent updated from an unknown version to 0.6.2.');
});
