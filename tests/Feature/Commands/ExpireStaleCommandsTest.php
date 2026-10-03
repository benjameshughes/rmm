<?php

declare(strict_types=1);

use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('api.commands');
});

it('times out running commands that outlived their timeout plus grace', function (): void {
    $stale = DeviceCommand::factory()->create([
        'status' => CommandStatus::Running,
        'timeout_seconds' => 300,
        'started_at' => now()->subMinutes(11),
    ]);

    $this->artisan('commands:expire-stale')->assertSuccessful();

    $stale->refresh();
    expect($stale->status)->toBe(CommandStatus::TimedOut);
    expect($stale->completed_at)->not->toBeNull();
});

it('requeues sent commands that never reported starting instead of timing them out', function (): void {
    $unstarted = DeviceCommand::factory()->create([
        'status' => CommandStatus::Sent,
        'timeout_seconds' => 60,
        'sent_at' => now()->subMinutes(7),
        'started_at' => null,
    ]);

    $this->artisan('commands:expire-stale')->assertSuccessful();

    $unstarted->refresh();
    expect($unstarted->status)->toBe(CommandStatus::Pending)
        ->and($unstarted->sent_at)->toBeNull()
        ->and($unstarted->completed_at)->toBeNull();
});

it('logs each requeued command', function (): void {
    Log::spy();
    Log::shouldReceive('channel')->andReturnSelf();
    $unstarted = DeviceCommand::factory()->create([
        'status' => CommandStatus::Sent,
        'sent_at' => now()->subMinutes(2),
        'started_at' => null,
    ]);

    $this->artisan('commands:expire-stale')->assertSuccessful();

    Log::shouldHaveReceived('info')->with('command.requeued', ['device_id' => $unstarted->device_id, 'command_id' => $unstarted->id])->once();
});

it('gives a freshly sent command time to report starting', function (): void {
    $justSent = DeviceCommand::factory()->create([
        'status' => CommandStatus::Sent,
        'sent_at' => now()->subSeconds(config('commands.unstarted_requeue_seconds') - 10),
        'started_at' => null,
    ]);

    $this->artisan('commands:expire-stale')->assertSuccessful();

    expect($justSent->refresh()->status)->toBe(CommandStatus::Sent);
});

it('takes the requeue window from config', function (): void {
    config(['commands.unstarted_requeue_seconds' => 600]);
    $unstarted = DeviceCommand::factory()->create([
        'status' => CommandStatus::Sent,
        'sent_at' => now()->subMinutes(5),
        'started_at' => null,
    ]);

    $this->artisan('commands:expire-stale')->assertSuccessful();

    expect($unstarted->refresh()->status)->toBe(CommandStatus::Sent);
});

it('never requeues a running command, however long it has run', function (): void {
    $running = DeviceCommand::factory()->create([
        'status' => CommandStatus::Running,
        'timeout_seconds' => 3600,
        'sent_at' => now()->subMinutes(30),
        'started_at' => now()->subMinutes(30),
    ]);

    $this->artisan('commands:expire-stale')->assertSuccessful();

    expect($running->refresh()->status)->toBe(CommandStatus::Running);
});

it('offers a requeued command to the agent again', function (): void {
    $device = Device::factory()->active()->withApiKey('requeue-key')->create();
    $command = DeviceCommand::factory()->pending()->create(['device_id' => $device->id]);

    $this->getJson('/api/commands/pending', ['X-Agent-Key' => 'requeue-key'])->assertJsonPath('command.id', $command->id);
    $this->travel(config('commands.unstarted_requeue_seconds') + 1)->seconds();
    $this->artisan('commands:expire-stale')->assertSuccessful();

    $this->getJson('/api/commands/pending', ['X-Agent-Key' => 'requeue-key'])->assertJsonPath('command.id', $command->id);
    expect($command->refresh()->status)->toBe(CommandStatus::Sent);
});

it('leaves commands that are still within their window alone', function (): void {
    $running = DeviceCommand::factory()->create([
        'status' => CommandStatus::Running,
        'timeout_seconds' => 300,
        'started_at' => now()->subMinutes(6),
    ]);
    $pending = DeviceCommand::factory()->pending()->create([
        'queued_at' => now()->subDay(),
    ]);
    $completed = DeviceCommand::factory()->completed()->create([
        'started_at' => now()->subDay(),
    ]);

    $this->artisan('commands:expire-stale')->assertSuccessful();

    expect($running->refresh()->status)->toBe(CommandStatus::Running);
    expect($pending->refresh()->status)->toBe(CommandStatus::Pending);
    expect($completed->refresh()->status)->toBe(CommandStatus::Completed);
});

it('takes the grace period from config', function (): void {
    config(['commands.stale_grace_seconds' => 0]);

    $command = DeviceCommand::factory()->create([
        'status' => CommandStatus::Running,
        'timeout_seconds' => 60,
        'started_at' => now()->subMinutes(2),
    ]);

    $this->artisan('commands:expire-stale')->assertSuccessful();

    expect($command->refresh()->status)->toBe(CommandStatus::TimedOut);
});
