<?php

declare(strict_types=1);

use App\Actions\ServerBackup\StoreServerBackups;
use App\Actions\ServerBackup\SyncServerBackupAlerts;
use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Enums\ServerBackupHealth;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\ServerBackupJob;
use App\Models\User;
use App\Notifications\AlertTriggered;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config([
        'backup.servers.stale_after_minutes' => 150,
        'backup.servers.shrink_threshold' => 0.5,
        'backup.servers.shrink_baseline_snapshots' => 5,
        'backup.servers.forget_missing_after_hours' => 72,
    ]);
});

/**
 * Reports one job whose snapshots processed these byte counts, oldest first, an hour apart.
 *
 * @param  array<int, int|null>  $bytesOldestFirst
 */
function reportJobSizes(Device $device, array $bytesOldestFirst): ServerBackupJob
{
    $count = count($bytesOldestFirst);
    $snapshots = collect($bytesOldestFirst)
        ->values()
        ->map(fn (?int $bytes, int $index): array => [
            'id' => str_pad(dechex(0xB0000 + $index), 64, '0'),
            'time' => now()->subHours($count - 1 - $index)->toRfc3339String(),
            'paths' => ['/all-databases.sql'],
            'summary' => $bytes === null ? null : ['total_bytes_processed' => $bytes, 'data_added' => 0],
        ])
        ->reverse()
        ->values()
        ->all();

    app(StoreServerBackups::class)($device, [[
        'job' => 'mariadb', 'tool' => 'restic', 'repository' => 'sftp:scarif:/backups', 'exit_code' => 0,
        'started_at' => now()->subMinutes(5)->toRfc3339String(), 'finished_at' => now()->toRfc3339String(),
        'file_modified_at' => now()->toRfc3339String(), 'snapshot_count' => $count, 'snapshots' => $snapshots,
        'stats' => null, 'error' => null,
    ]]);

    return ServerBackupJob::query()->where('device_id', $device->id)->sole();
}

it('works out where a job stands', function (Closure $job, ServerBackupHealth $health): void {
    expect($job()->health())->toBe($health);
})->with([
    'ran half an hour ago' => [fn (): ServerBackupJob => ServerBackupJob::factory()->create(), ServerBackupHealth::Healthy],
    'two hours since the last snapshot' => [fn (): ServerBackupJob => ServerBackupJob::factory()->overdue(hours: 2)->create(), ServerBackupHealth::Healthy],
    'three hours since the last snapshot' => [fn (): ServerBackupJob => ServerBackupJob::factory()->overdue(hours: 3)->create(), ServerBackupHealth::Overdue],
    'never snapshotted, first seen long ago' => [fn (): ServerBackupJob => ServerBackupJob::factory()->create(['latest_snapshot_at' => null, 'finished_at' => null, 'created_at' => now()->subHours(4)]), ServerBackupHealth::Overdue],
    'exit code 1' => [fn (): ServerBackupJob => ServerBackupJob::factory()->failed()->create(), ServerBackupHealth::Failed],
    'restic partial, exit code 3' => [fn (): ServerBackupJob => ServerBackupJob::factory()->failed(exitCode: 3)->create(), ServerBackupHealth::Failed],
    'backed up 0 bytes' => [fn (): ServerBackupJob => ServerBackupJob::factory()->shrunk()->create(), ServerBackupHealth::Shrunk],
    'backed up under half the usual' => [fn (): ServerBackupJob => ServerBackupJob::factory()->shrunk(latestBytes: 500_000_000)->create(), ServerBackupHealth::Shrunk],
    'backed up a bit less than usual' => [fn (): ServerBackupJob => ServerBackupJob::factory()->shrunk(latestBytes: 700_000_000)->create(), ServerBackupHealth::Healthy],
    'status file unreadable' => [fn (): ServerBackupJob => ServerBackupJob::factory()->unreadable()->create(), ServerBackupHealth::Unreadable],
    'status file not reported for days' => [fn (): ServerBackupJob => ServerBackupJob::factory()->missing()->failed()->create(), ServerBackupHealth::Missing],
    'failed beats overdue' => [fn (): ServerBackupJob => ServerBackupJob::factory()->failed()->overdue()->create(), ServerBackupHealth::Failed],
]);

it('gives every health a label, colour and icon, with red rows first', function (): void {
    expect(collect(ServerBackupHealth::cases())->filter(fn (ServerBackupHealth $health): bool => $health->needsAttention())->count())->toBe(5)
        ->and(ServerBackupHealth::Failed->rank())->toBeLessThan(ServerBackupHealth::Overdue->rank())
        ->and(ServerBackupHealth::Overdue->rank())->toBeLessThan(ServerBackupHealth::Healthy->rank())
        ->and(ServerBackupHealth::Missing->label())->toBe('Status file missing')
        ->and(ServerBackupHealth::Shrunk->color())->toBe('red')
        ->and(collect(ServerBackupHealth::cases())->map(fn (ServerBackupHealth $health): string => $health->icon())->unique())->toHaveCount(6);
});

it('flags the mysqldump that died and backed up 0 bytes every hour, and keeps flagging it', function (): void {
    $device = Device::factory()->active()->monitorOnly()->create();

    $job = reportJobSizes($device, [1_200_000_000, 1_210_000_000, 1_190_000_000, 1_220_000_000, 1_200_000_000, 0]);

    expect($job->baseline_bytes_processed)->toBe(1_200_000_000)
        ->and($job->latest_bytes_processed)->toBe(0)
        ->and($job->health())->toBe(ServerBackupHealth::Shrunk)
        ->and($job->problem())->toBe('latest snapshot backed up 0 B, usually about 1.1 GB');

});

it('still flags it after seventeen days of 0-byte snapshots have pushed the good ones out of the window', function (): void {
    config(['backup.servers.max_snapshots' => 5]);
    $device = Device::factory()->active()->monitorOnly()->create();
    $good = array_fill(0, 5, 1_200_000_000);

    reportJobSizes($device, $good);
    $this->travel(17 * 24)->hours();
    $job = reportJobSizes($device, [...$good, ...array_fill(0, 17 * 24, 0)]);

    expect($job->snapshots()->count())->toBe(10)
        ->and($job->health())->toBe(ServerBackupHealth::Shrunk);
});

it('does not call a steady or growing job shrunk, nor judge snapshots without a summary', function (array $bytes): void {
    expect(reportJobSizes(Device::factory()->active()->monitorOnly()->create(), $bytes)->health())->toBe(ServerBackupHealth::Healthy);
})->with([
    'growing' => [[1_000, 1_100, 1_200, 1_300, 1_400, 1_500]],
    'latest has no summary' => [[1_000, 1_100, 1_200, null]],
    'first snapshot' => [[1_000]],
]);

it('raises one alert per unhealthy job for humans, keeps its message current and resolves it', function (): void {
    Notification::fake();
    $human = User::factory()->create();
    $automation = User::factory()->create(['email' => config('devices.automation_user.email')]);
    $device = Device::factory()->active()->monitorOnly()->create(['hostname' => 'achcto']);
    $job = ServerBackupJob::factory()->shrunk()->create(['device_id' => $device->id, 'job' => 'mariadb']);
    ServerBackupJob::factory()->create(['device_id' => $device->id, 'job' => 'forgejo']);

    app(SyncServerBackupAlerts::class)($device);
    app(SyncServerBackupAlerts::class)($device);

    $alert = Alert::query()->sole();

    expect($alert->metric)->toBe(AlertMetric::ServerBackupProblem)
        ->and($alert->subject)->toBe('mariadb')
        ->and($alert->status)->toBe(AlertStatus::Triggered)
        ->and($alert->message)->toBe('achcto mariadb backup: latest snapshot backed up 0 B, usually about 1.1 GB')
        ->and($alert->alertRule->name)->toBe(config('backup.servers.alert.rule_name'));

    Notification::assertSentTo($human, AlertTriggered::class);
    Notification::assertNotSentTo($automation, AlertTriggered::class);

    $job->update(['last_exit_code' => 1]);
    app(SyncServerBackupAlerts::class)($device);

    expect($alert->fresh()->message)->toStartWith('achcto mariadb backup: last run exited with code 1');

    $job->update(['last_exit_code' => 0, 'latest_bytes_processed' => 1_200_000_000]);
    app(SyncServerBackupAlerts::class)($device);

    expect($alert->fresh()->status)->toBe(AlertStatus::Resolved);
});

it('raises nothing while the built-in rule is switched off', function (): void {
    AlertRule::serverBackupProblem()->update(['is_active' => false]);
    $device = Device::factory()->active()->monitorOnly()->create();
    ServerBackupJob::factory()->failed()->create(['device_id' => $device->id]);

    app(SyncServerBackupAlerts::class)($device);

    expect(Alert::query()->count())->toBe(0)
        ->and(AlertMetric::ServerBackupProblem->isThresholdBased())->toBeFalse()
        ->and(AlertMetric::thresholdBased())->not->toContain(AlertMetric::ServerBackupProblem);
});

it('syncs alerts as reports arrive and in the hourly check', function (): void {
    $device = Device::factory()->active()->monitorOnly()->create();

    reportJobSizes($device, [1_200_000_000, 0]);

    expect(Alert::query()->where('metric', AlertMetric::ServerBackupProblem)->count())->toBe(1);

    $quiet = Device::factory()->active()->monitorOnly()->create();
    ServerBackupJob::factory()->overdue(hours: 6)->create(['device_id' => $quiet->id]);

    $this->artisan('backups:check')->assertSuccessful();

    expect(Alert::query()->where('device_id', $quiet->id)->where('metric', AlertMetric::ServerBackupProblem)->count())->toBe(1);
});
