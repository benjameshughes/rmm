<?php

declare(strict_types=1);

use App\Enums\CommandStatus;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('lets the user who queued a pending command cancel it', function (): void {
    $command = DeviceCommand::factory()->pending()->create(['queued_by' => $this->user->id]);

    expect($this->user->can('cancel', $command))->toBeTrue();
});

it('refuses to let anyone else cancel a pending command', function (): void {
    $command = DeviceCommand::factory()->pending()->create();

    expect($this->user->can('cancel', $command))->toBeFalse();
});

it('refuses to cancel your own command once the agent has fetched it', function (CommandStatus $status): void {
    $command = DeviceCommand::factory()->create(['queued_by' => $this->user->id, 'status' => $status]);

    expect($this->user->can('cancel', $command))->toBeFalse();
})->with([
    'sent' => CommandStatus::Sent,
    'running' => CommandStatus::Running,
    'completed' => CommandStatus::Completed,
    'failed' => CommandStatus::Failed,
    'timed out' => CommandStatus::TimedOut,
    'cancelled' => CommandStatus::Cancelled,
]);
