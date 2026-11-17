<?php

declare(strict_types=1);

use App\Actions\Schedule\RunScheduledTask;
use App\Enums\CommandStatus;
use App\Enums\ScheduleTargetType;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('executes script on all active devices for All target', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();
    Device::factory()->active()->count(3)->create();
    Device::factory()->create();

    $task = ScheduledTask::factory()->create([
        'script_id' => $script->id,
        'target_type' => ScheduleTargetType::All,
        'created_by' => $user->id,
    ]);

    $count = app(RunScheduledTask::class)($task);

    expect($count)->toBe(3);
    expect(DeviceCommand::count())->toBe(3);
    expect(DeviceCommand::where('script_id', $script->id)->count())->toBe(3);
});

it('executes script on devices in a group', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();
    $group = DeviceGroup::factory()->create();
    Device::factory()->active()->count(2)->create(['device_group_id' => $group->id]);
    Device::factory()->active()->create();

    $task = ScheduledTask::factory()->create([
        'script_id' => $script->id,
        'target_type' => ScheduleTargetType::Group,
        'target_id' => $group->id,
        'created_by' => $user->id,
    ]);

    $count = app(RunScheduledTask::class)($task);

    expect($count)->toBe(2);
    expect(DeviceCommand::count())->toBe(2);
});

it('executes script on devices with a tag', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();
    $tag = Tag::factory()->create();
    $tagged1 = Device::factory()->active()->create();
    $tagged1->tags()->attach($tag->id);
    $tagged2 = Device::factory()->active()->create();
    $tagged2->tags()->attach($tag->id);
    Device::factory()->active()->create();

    $task = ScheduledTask::factory()->create([
        'script_id' => $script->id,
        'target_type' => ScheduleTargetType::Tag,
        'target_id' => $tag->id,
        'created_by' => $user->id,
    ]);

    $count = app(RunScheduledTask::class)($task);

    expect($count)->toBe(2);
});

it('executes script on a single device', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();
    $device = Device::factory()->active()->create();
    Device::factory()->active()->create();

    $task = ScheduledTask::factory()->create([
        'script_id' => $script->id,
        'target_type' => ScheduleTargetType::Device,
        'target_id' => $device->id,
        'created_by' => $user->id,
    ]);

    $count = app(RunScheduledTask::class)($task);

    expect($count)->toBe(1);
    expect(DeviceCommand::first()->device_id)->toBe($device->id);
});

it('skips inactive tasks', function (): void {
    $task = ScheduledTask::factory()->inactive()->create();

    $count = app(RunScheduledTask::class)($task);

    expect($count)->toBe(0);
    expect(DeviceCommand::count())->toBe(0);
});

it('updates last_run_at after running', function (): void {
    $user = User::factory()->create();
    $task = ScheduledTask::factory()->create([
        'created_by' => $user->id,
        'last_run_at' => null,
    ]);

    app(RunScheduledTask::class)($task);

    $task->refresh();
    expect($task->last_run_at)->not->toBeNull();
});

it('skips devices that still have the same script queued from an earlier run', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();
    $offline = Device::factory()->active()->create();
    $online = Device::factory()->active()->create();

    $task = ScheduledTask::factory()->create([
        'script_id' => $script->id,
        'target_type' => ScheduleTargetType::All,
        'created_by' => $user->id,
    ]);

    app(RunScheduledTask::class)($task);
    DeviceCommand::where('device_id', $online->id)->first()->markAsCompleted('done', 0);

    $count = app(RunScheduledTask::class)($task);

    expect($count)->toBe(1)
        ->and(DeviceCommand::where('device_id', $offline->id)->count())->toBe(1)
        ->and(DeviceCommand::where('device_id', $online->id)->count())->toBe(2);
});

it('still queues the script when the queued copy has different parameters', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();
    $device = Device::factory()->active()->create();

    DeviceCommand::factory()->create([
        'device_id' => $device->id,
        'script_id' => $script->id,
        'parameters' => ['package' => 'firefox'],
        'status' => CommandStatus::Pending,
    ]);

    $task = ScheduledTask::factory()->create([
        'script_id' => $script->id,
        'target_type' => ScheduleTargetType::Device,
        'target_id' => $device->id,
        'created_by' => $user->id,
    ]);

    expect(app(RunScheduledTask::class)($task))->toBe(1);
});

it('skips devices that have been offline longer than the configured days', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();
    Device::factory()->active()->create();
    $gone = Device::factory()->active()->create([
        'last_seen' => now()->subDays(config('commands.schedule_skip_offline_days'))->subHour(),
    ]);

    $task = ScheduledTask::factory()->create([
        'script_id' => $script->id,
        'target_type' => ScheduleTargetType::All,
        'created_by' => $user->id,
    ]);

    expect(app(RunScheduledTask::class)($task))->toBe(1)
        ->and(DeviceCommand::where('device_id', $gone->id)->exists())->toBeFalse();
});
