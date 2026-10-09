<?php

declare(strict_types=1);

use App\Enums\CommandStatus;
use App\Events\CommandProgressed;
use App\Events\CommandUpdated;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->device = Device::factory()->active()->withApiKey('PROGRESS-KEY')->create();
    $this->command = DeviceCommand::factory()->create(['device_id' => $this->device->id, 'status' => CommandStatus::Running, 'started_at' => now()]);
});

/**
 * @param  array<string, mixed>  $progress
 */
function postProgress(DeviceCommand $command, array $progress, ?string $at = '2026-10-09T08:00:00.123456+00:00', string $key = 'PROGRESS-KEY'): Illuminate\Testing\TestResponse
{
    return test()->withHeaders(['X-Agent-Key' => $key])->postJson("/api/commands/{$command->id}/progress", array_filter(['progress' => $progress, 'at' => $at], fn (mixed $value): bool => $value !== null));
}

function fullProgress(): array
{
    return [
        'schema' => 'rmm.progress/1',
        'percent' => 42.5,
        'done' => 7612,
        'total' => 18128,
        'unit' => 'files',
        'bytes_done' => 1288490188,
        'bytes_total' => 3328599654,
        'eta_seconds' => 312,
        'message' => 'Backing up',
        'current' => 'C:\\Users\\sophie\\Documents\\x.xlsx',
    ];
}

it('keeps the newest progress of a running command with the agent time it was taken', function (): void {
    postProgress($this->command, fullProgress())->assertSuccessful()->assertJson(['message' => 'OK']);

    $command = $this->command->fresh();

    expect($command->progress)->toBe(fullProgress())
        ->and($command->progress_at->toIso8601String())->toBe('2026-10-09T08:00:00+00:00')
        ->and($command->liveProgress()->label())->toBe('42% · 7,612 / 18,128 files · 1.2 / 3.1 GB · ~5 min left');
});

it('takes progress for a command the agent has fetched but not yet started', function (): void {
    $this->command->update(['status' => CommandStatus::Sent]);

    postProgress($this->command, fullProgress())->assertSuccessful();

    expect($this->command->fresh()->progress)->not->toBeNull();
});

it('refuses an agent without a valid key', function (): void {
    postProgress($this->command, fullProgress(), key: 'WRONG-KEY')->assertUnauthorized();

    expect($this->command->fresh()->progress)->toBeNull();
});

it('answers 404 for another device\'s command or one that does not exist', function (): void {
    $other = DeviceCommand::factory()->create(['status' => CommandStatus::Running]);

    postProgress($other, fullProgress())->assertNotFound();
    $this->withHeaders(['X-Agent-Key' => 'PROGRESS-KEY'])->postJson('/api/commands/999999/progress', ['progress' => fullProgress()])->assertNotFound();

    expect($other->fresh()->progress)->toBeNull();
});

it('answers 409 once the command is no longer with the agent', function (CommandStatus $status): void {
    $this->command->update(['status' => $status]);

    postProgress($this->command, fullProgress())->assertConflict();

    expect($this->command->fresh()->progress)->toBeNull();
})->with([
    CommandStatus::Pending,
    CommandStatus::Completed,
    CommandStatus::Failed,
    CommandStatus::TimedOut,
    CommandStatus::Cancelled,
]);

it('reads the progress leniently: odd fields are dropped, strings capped, unknown keys ignored', function (): void {
    postProgress($this->command, [
        'percent' => 140,
        'done' => 'lots',
        'total' => -5,
        'unit' => ['files'],
        'bytes_done' => '2048',
        'message' => str_repeat('m', 500),
        'current' => str_repeat('c', 5000),
        'secret' => 'never stored',
    ])->assertSuccessful();

    $progress = $this->command->fresh()->progress;

    expect($progress)->toHaveKeys(['schema', 'percent', 'bytes_done', 'message', 'current'])
        ->not->toHaveKeys(['done', 'total', 'unit', 'secret'])
        ->and($progress['schema'])->toBe('rmm.progress/1')
        ->and($progress['percent'])->toEqual(100)
        ->and($progress['bytes_done'])->toBe(2048)
        ->and(mb_strlen($progress['message']))->toBe(config('commands.progress.max_message_length'))
        ->and(mb_strlen($progress['current']))->toBe(config('commands.progress.max_current_length'));
});

it('refuses a body without a progress object or with a bad time', function (array $body): void {
    $this->withHeaders(['X-Agent-Key' => 'PROGRESS-KEY'])
        ->postJson("/api/commands/{$this->command->id}/progress", $body)
        ->assertUnprocessable();

    expect($this->command->fresh()->progress)->toBeNull();
})->with([
    'no progress' => [['at' => '2026-10-09T08:00:00+00:00']],
    'progress is a string' => [['progress' => 'halfway']],
    'bad time' => [['progress' => ['schema' => 'rmm.progress/1'], 'at' => 'yesterday-ish']],
]);

it('uses the server time when the agent sends none', function (): void {
    $this->freezeSecond();

    postProgress($this->command, fullProgress(), at: null)->assertSuccessful();

    expect($this->command->fresh()->progress_at->equalTo(now()))->toBeTrue();
});

it('ignores a report older than the one it already has', function (): void {
    postProgress($this->command, [...fullProgress(), 'percent' => 60], '2026-10-09T08:00:10+00:00')->assertSuccessful();
    postProgress($this->command, [...fullProgress(), 'percent' => 50], '2026-10-09T08:00:05+00:00')->assertSuccessful()->assertJson(['message' => 'Ignored']);
    postProgress($this->command, [...fullProgress(), 'percent' => 55], '2026-10-09T08:00:10+00:00')->assertJson(['message' => 'Ignored']);

    expect($this->command->fresh()->progress['percent'])->toEqual(60);

    postProgress($this->command, [...fullProgress(), 'percent' => 70], '2026-10-09T09:00:15+01:00')->assertJson(['message' => 'OK']);

    expect($this->command->fresh()->progress['percent'])->toEqual(70);
});

it('announces progress with CommandProgressed only, never CommandUpdated or an audit entry', function (): void {
    Event::fake([CommandProgressed::class, CommandUpdated::class]);
    $auditRows = AuditLog::query()->count();

    postProgress($this->command, fullProgress())->assertSuccessful();
    postProgress($this->command, fullProgress(), '2026-10-09T07:00:00+00:00')->assertSuccessful();

    Event::assertDispatchedTimes(CommandProgressed::class, 1);
    Event::assertDispatched(CommandProgressed::class, fn (CommandProgressed $event): bool => $event->commandId === $this->command->id && $event->deviceId === $this->device->id);
    Event::assertNotDispatched(CommandUpdated::class);
    expect(AuditLog::query()->count())->toBe($auditRows);
});

it('keeps the last progress after the command finishes, but only shows it while running', function (): void {
    postProgress($this->command, fullProgress())->assertSuccessful();

    $this->command->refresh()->markAsCompleted('OK: done', 0);

    expect($this->command->fresh())
        ->progress->toBe(fullProgress())
        ->liveProgress()->toBeNull();
});

it('forgets the progress of a command put back in the queue', function (): void {
    $this->command->update(['status' => CommandStatus::Sent]);
    postProgress($this->command, fullProgress())->assertSuccessful();

    $this->command->refresh()->requeue();

    expect($this->command->fresh())->progress->toBeNull()->progress_at->toBeNull();
});

it('strips PROGRESS lines an older agent left in the result output', function (): void {
    $output = "OK: backed up 2 user profiles\nPROGRESS: {\"schema\":\"rmm.progress/1\",\"percent\":10}\n  PROGRESS: {\"percent\":90}\nProfiles: anna, ben\n{\"status\":\"ok\"}";

    $this->withHeaders(['X-Agent-Key' => 'PROGRESS-KEY'])
        ->postJson("/api/commands/{$this->command->id}/result", ['exit_code' => 0, 'output' => $output])
        ->assertSuccessful();

    $command = $this->command->fresh();

    expect($command->output)->toBe("OK: backed up 2 user profiles\nProfiles: anna, ben\n{\"status\":\"ok\"}")
        ->and($command->summaryLine())->toBe('OK: backed up 2 user profiles')
        ->and($command->resultJson())->toBe(['status' => 'ok']);
});
