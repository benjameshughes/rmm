<?php

declare(strict_types=1);

use App\Events\ServerBackupsReported;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceMetric;
use App\Models\ServerBackupJob;
use App\Models\ServerBackupSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->travelTo(now()->startOfHour()->addMinutes(10));
    $this->server = Device::factory()->withApiKey('SERVER-KEY')->monitorOnly()->create(['hostname' => 'achcto']);
});

/**
 * A raw restic snapshot object as `restic snapshots --json` prints it.
 *
 * @return array<string, mixed>
 */
function resticSnapshot(string $id, Carbon $time, ?int $bytes = 1_200_000_000, string $path = '/all-databases.sql'): array
{
    return [
        'id' => str_pad($id, 64, '0'),
        'short_id' => substr(str_pad($id, 64, '0'), 0, 8),
        'time' => $time->toRfc3339String(),
        'hostname' => 'achcto',
        'paths' => [$path],
        'tags' => ['hourly'],
        'program_version' => 'restic 0.17.3',
        'summary' => $bytes === null ? null : [
            'backup_start' => $time->copy()->subMinutes(4)->toRfc3339String(),
            'backup_end' => $time->toRfc3339String(),
            'files_new' => 1,
            'files_changed' => 0,
            'files_unmodified' => 0,
            'dirs_new' => 0,
            'dirs_changed' => 1,
            'dirs_unmodified' => 0,
            'data_blobs' => 3,
            'tree_blobs' => 1,
            'data_added' => 4_000_000,
            'data_added_packed' => 1_500_000,
            'total_files_processed' => 1,
            'total_bytes_processed' => $bytes,
        ],
    ];
}

/**
 * One status file entry as the agent sends it.
 *
 * @param  array<int, array<string, mixed>>  $snapshots
 * @return array<string, mixed>
 */
function backupEntry(string $job = 'mariadb', array $snapshots = [], array $overrides = []): array
{
    return [
        'job' => $job,
        'tool' => 'restic',
        'repository' => 'sftp:scarif:/mnt/scarif/data/backups/achcto',
        'exit_code' => 0,
        'started_at' => now()->subMinutes(5)->toRfc3339String(),
        'finished_at' => now()->subMinute()->toRfc3339String(),
        'file_modified_at' => now()->subMinute()->toRfc3339String(),
        'snapshot_count' => count($snapshots),
        'snapshots' => $snapshots,
        'stats' => ['total_size' => 3_000_000_000, 'total_uncompressed_size' => 9_000_000_000, 'compression_ratio' => 3.0, 'compression_space_saving' => 66.7, 'total_blob_count' => 1200, 'snapshots_count' => count($snapshots)],
        'error' => null,
        ...$overrides,
    ];
}

function postBackups(mixed $backups): TestResponse
{
    return test()->withHeaders(['X-Device-Key' => 'SERVER-KEY'])->postJson('/api/metrics', [
        'monitor_only' => true,
        'agent_version' => '0.9.1',
        'cpu' => ['usage_percent' => 5],
        'memory' => ['usage_percent' => 30],
        'system_info' => ['os_name' => 'Debian GNU/Linux', 'kernel_name' => 'Linux'],
        'backups' => $backups,
    ]);
}

/**
 * Hourly snapshots, newest first.
 *
 * @return array<int, array<string, mixed>>
 */
function hourlySnapshots(int $count, int $bytes = 1_200_000_000, int $startHoursAgo = 0): array
{
    return collect(range(0, $count - 1))
        ->map(fn (int $hour): array => resticSnapshot(dechex(0xA0000 + $hour + $startHoursAgo), now()->subHours($hour + $startHoursAgo)->startOfHour(), $bytes))
        ->all();
}

it('stores each job, its repository stats and its snapshots from a metrics report', function (): void {
    postBackups([backupEntry('mariadb', hourlySnapshots(3)), backupEntry('forgejo', hourlySnapshots(2))])->assertSuccessful();

    $job = ServerBackupJob::query()->where('job', 'mariadb')->sole();

    expect($job->device_id)->toBe($this->server->id)
        ->and($job->tool)->toBe('restic')
        ->and($job->repository)->toBe('sftp:scarif:/mnt/scarif/data/backups/achcto')
        ->and($job->last_exit_code)->toBe(0)
        ->and($job->snapshot_count)->toBe(3)
        ->and($job->total_size)->toBe(3_000_000_000)
        ->and($job->compression_ratio)->toBe(3.0)
        ->and($job->latest_bytes_processed)->toBe(1_200_000_000)
        ->and($job->baseline_bytes_processed)->toBe(1_200_000_000)
        ->and($job->latest_snapshot_at->equalTo(now()->startOfHour()))->toBeTrue()
        ->and($job->snapshots()->count())->toBe(3)
        ->and(ServerBackupSnapshot::query()->count())->toBe(5);

    $snapshot = $job->snapshots()->latest('taken_at')->first();

    expect($snapshot->paths)->toBe(['/all-databases.sql'])
        ->and($snapshot->tags)->toBe(['hourly'])
        ->and($snapshot->duration_seconds)->toBe(240.0)
        ->and($snapshot->files_new)->toBe(1)
        ->and($snapshot->data_added)->toBe(4_000_000)
        ->and($snapshot->total_bytes_processed)->toBe(1_200_000_000);
});

it('leaves the device monitor only and queues nothing on it', function (): void {
    postBackups([backupEntry('mariadb', hourlySnapshots(2))])->assertSuccessful();

    expect($this->server->fresh()->isMonitorOnly)->toBeTrue()
        ->and(DeviceCommand::query()->count())->toBe(0);
});

it('only rewrites a job when its status file changed', function (): void {
    Event::fake([ServerBackupsReported::class]);
    $entry = backupEntry('mariadb', hourlySnapshots(3));

    postBackups([$entry])->assertSuccessful();
    $firstSnapshotWrite = ServerBackupSnapshot::query()->max('updated_at');

    $this->travel(1)->minutes();
    postBackups([$entry])->assertSuccessful();

    expect(ServerBackupSnapshot::query()->max('updated_at'))->toBe($firstSnapshotWrite)
        ->and(ServerBackupJob::query()->sole()->last_reported_at->equalTo(now()->startOfSecond()))->toBeTrue();

    Event::assertDispatchedTimes(ServerBackupsReported::class, 2);
    Event::assertDispatched(ServerBackupsReported::class, fn (ServerBackupsReported $event): bool => $event->hasChanged);
    Event::assertDispatched(ServerBackupsReported::class, fn (ServerBackupsReported $event): bool => ! $event->hasChanged && ! $event->broadcastWhen());
});

it('skips bad entries and bad snapshots without failing the report', function (): void {
    postBackups([
        'not an entry',
        ['job' => '../../etc/passwd', 'tool' => 'restic'],
        ['tool' => 'restic'],
        backupEntry('forgejo', [...hourlySnapshots(2), ['id' => 'not-hex!', 'time' => 'yesterday-ish'], ['short_id' => 'abc']], ['exit_code' => 'zero?']),
        backupEntry('cantina', [...hourlySnapshots(2), ['id' => 'nothex', 'time' => now()->toRfc3339String()], ['id' => str_repeat('f', 64), 'time' => 'not a date']], ['repository' => str_repeat('r', 900), 'surprise' => ['nested' => true]]),
    ])->assertSuccessful();

    $cantina = ServerBackupJob::query()->sole();

    expect($cantina->job)->toBe('cantina')
        ->and(mb_strlen($cantina->repository))->toBeLessThanOrEqual(500)
        ->and($cantina->snapshots()->count())->toBe(2)
        ->and(DeviceMetric::query()->count())->toBe(1);
});

it('ignores a backups section that is not a list, and an old agent that sends none', function (): void {
    postBackups(['job' => 'mariadb'])->assertSuccessful();

    $this->withHeaders(['X-Device-Key' => 'SERVER-KEY'])->postJson('/api/metrics', ['cpu' => ['usage_percent' => 5]])->assertSuccessful();

    expect(ServerBackupJob::query()->count())->toBe(0);
});

it('caps a report at the configured number of jobs', function (): void {
    config(['backup.servers.max_jobs' => 2]);

    postBackups([backupEntry('one'), backupEntry('two'), backupEntry('three')])->assertSuccessful();

    expect(ServerBackupJob::query()->pluck('job')->sort()->values()->all())->toBe(['one', 'two']);
});

it('keeps the backups out of the raw payload it stores while debugging', function (): void {
    config(['devices.metrics.store_raw_payload' => true]);

    postBackups([backupEntry('mariadb', hourlySnapshots(2))])->assertSuccessful();

    expect(DeviceMetric::query()->sole()->payload)->not->toHaveKey('backups')
        ->toHaveKey('agent_version');
});

it('deletes snapshots restic forgot inside the window it was sent', function (): void {
    postBackups([backupEntry('mariadb', hourlySnapshots(4))])->assertSuccessful();

    $this->travel(1)->minutes();
    $kept = hourlySnapshots(4);
    unset($kept[1]);
    postBackups([backupEntry('mariadb', array_values($kept), ['file_modified_at' => now()->toRfc3339String()])])->assertSuccessful();

    expect(ServerBackupSnapshot::query()->pluck('snapshot_id')->sort()->values()->all())
        ->toBe(collect($kept)->pluck('id')->sort()->values()->all());
});

it('keeps older snapshots the agent no longer sends while restic still holds them', function (): void {
    config(['backup.servers.max_snapshots' => 3]);

    postBackups([backupEntry('mariadb', hourlySnapshots(3, startHoursAgo: 2), ['snapshot_count' => 3])])->assertSuccessful();

    $this->travel(1)->minutes();
    postBackups([backupEntry('mariadb', hourlySnapshots(3), ['snapshot_count' => 5, 'file_modified_at' => now()->toRfc3339String()])])->assertSuccessful();

    expect(ServerBackupSnapshot::query()->count())->toBe(5);

    $this->travel(1)->minutes();
    postBackups([backupEntry('mariadb', hourlySnapshots(3), ['snapshot_count' => 4, 'file_modified_at' => now()->toRfc3339String()])])->assertSuccessful();

    expect(ServerBackupSnapshot::query()->count())->toBe(4)
        ->and(ServerBackupSnapshot::query()->min('taken_at'))->toBe(now()->subHours(3)->startOfHour()->format('Y-m-d H:i:s'));
});

it('only reads the newest snapshots up to the configured window', function (): void {
    config(['backup.servers.max_snapshots' => 2]);

    postBackups([backupEntry('mariadb', hourlySnapshots(5), ['snapshot_count' => 1606])])->assertSuccessful();

    expect(ServerBackupSnapshot::query()->count())->toBe(2)
        ->and(ServerBackupJob::query()->sole()->snapshot_count)->toBe(1606);
});

it('records an unreadable status file without losing what it knew', function (): void {
    postBackups([backupEntry('mariadb', hourlySnapshots(2))])->assertSuccessful();

    $this->travel(1)->minutes();
    postBackups([[
        'job' => 'mariadb', 'tool' => null, 'repository' => null, 'exit_code' => null, 'started_at' => null, 'finished_at' => null,
        'file_modified_at' => now()->toRfc3339String(), 'snapshot_count' => 0, 'snapshots' => [], 'stats' => null,
        'error' => 'expected value at line 1 column 1',
    ]])->assertSuccessful();

    $job = ServerBackupJob::query()->sole();

    expect($job->last_error)->toBe('expected value at line 1 column 1')
        ->and($job->repository)->toBe('sftp:scarif:/mnt/scarif/data/backups/achcto')
        ->and($job->snapshots()->count())->toBe(2);
});

it('leaves jobs alone when the folder reports no status files, so they turn missing', function (): void {
    postBackups([backupEntry('mariadb', hourlySnapshots(2))])->assertSuccessful();

    $this->travel(config('backup.servers.forget_missing_after_hours') + 1)->hours();
    postBackups([])->assertSuccessful();

    $job = ServerBackupJob::query()->sole();

    expect($job->health()->value)->toBe('missing')
        ->and($job->snapshots()->count())->toBe(2);
});
