<?php

declare(strict_types=1);

use App\Enums\CommandStatus;
use App\Models\DeviceCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

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

it('times out sent commands that never reported starting', function (): void {
    $stale = DeviceCommand::factory()->create([
        'status' => CommandStatus::Sent,
        'timeout_seconds' => 60,
        'sent_at' => now()->subMinutes(7),
        'started_at' => null,
    ]);

    $this->artisan('commands:expire-stale')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(CommandStatus::TimedOut);
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
