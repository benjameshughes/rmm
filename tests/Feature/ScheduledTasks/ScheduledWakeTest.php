<?php

declare(strict_types=1);

use App\Actions\Schedule\RunScheduledTask;
use App\Enums\ScheduledTaskAction;
use App\Enums\ScheduleTargetType;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\ScheduledTask;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('wakes only the targeted devices that are asleep and have a MAC', function (): void {
    $listener = listenForWakePackets();
    $group = DeviceGroup::factory()->create();
    Device::factory()->active()->create(['device_group_id' => $group->id, 'last_seen' => now()->subHour(), 'mac_addresses' => ['AA:AA:AA:AA:AA:01']]);
    Device::factory()->active()->create(['device_group_id' => $group->id, 'last_seen' => now(), 'mac_addresses' => ['AA:AA:AA:AA:AA:02']]);
    Device::factory()->active()->create(['device_group_id' => $group->id, 'last_seen' => now()->subHour(), 'mac_addresses' => null]);
    Device::factory()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:AA:AA:AA:AA:03']]);

    $task = ScheduledTask::factory()->wake()->create([
        'target_type' => ScheduleTargetType::Group,
        'target_id' => $group->id,
    ]);

    $count = app(RunScheduledTask::class)($task);

    expect($count)->toBe(1)
        ->and(receivedWakePackets($listener))->toBe(array_fill(0, 3, magicPacketFor('AA:AA:AA:AA:AA:01')))
        ->and(DeviceCommand::query()->count())->toBe(0);
});

it('records the run of a wake schedule', function (): void {
    listenForWakePackets();
    $task = ScheduledTask::factory()->wake()->create(['last_run_at' => null, 'next_run_at' => null]);

    app(RunScheduledTask::class)($task);

    $task->refresh();
    expect($task->last_run_at)->not->toBeNull()
        ->and($task->next_run_at)->not->toBeNull();
});

it('does not wake anything for an inactive wake schedule', function (): void {
    $listener = listenForWakePackets();
    Device::factory()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:AA:AA:AA:AA:01']]);
    $task = ScheduledTask::factory()->wake()->inactive()->create();

    expect(app(RunScheduledTask::class)($task))->toBe(0)
        ->and(receivedWakePackets($listener))->toBeEmpty();
});

it('skips a script schedule whose script is missing', function (): void {
    $task = ScheduledTask::factory()->create(['script_id' => null]);
    Device::factory()->active()->create();

    expect(app(RunScheduledTask::class)($task))->toBe(0)
        ->and(DeviceCommand::query()->count())->toBe(0)
        ->and($task->fresh()->last_run_at)->toBeNull();
});

it('only needs a script for script runs', function (ScheduledTaskAction $action, bool $requiresScript): void {
    expect($action->requiresScript())->toBe($requiresScript)
        ->and($action->label())->not->toBeEmpty();
})->with([
    'run script' => [ScheduledTaskAction::RunScript, true],
    'wake' => [ScheduledTaskAction::Wake, false],
]);
