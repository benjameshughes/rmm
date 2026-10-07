<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Actions\Trends\CompareTrendPeriods;
use App\DTOs\Trends\FleetTrend;
use App\DTOs\Trends\MetricChange;
use App\DTOs\Trends\TrendChange;
use App\DTOs\Trends\TrendWindow;
use App\Enums\CommandStatus;
use App\Enums\TrendChangeKind;
use App\Enums\TrendDirection;
use App\Enums\TrendMetric;
use App\Enums\TrendPeriod;
use App\Enums\TrendScope;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceMetric;
use App\Models\DeviceSoftware;
use App\Models\ScheduledTask;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-07 12:00:00', 'UTC'));
    config(['trends.min_reports' => 3]);
});

/**
 * Reports for one device, one every $everyMinutes from $from, each taking the next value of every listed column.
 *
 * @param  array<string, array<int, mixed>>  $columns
 */
function trendReports(Device $device, string $from, array $columns, int $everyMinutes = 5): void
{
    $count = count(reset($columns));

    collect(range(0, $count - 1))->each(fn (int $index) => DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'recorded_at' => Carbon::parse($from, 'UTC')->addMinutes($index * $everyMinutes),
        'cpu' => 10.0,
        'ram' => 50.0,
        'uptime_seconds' => 100_000 + $index * $everyMinutes * 60,
        ...collect($columns)->map(fn (array $values): mixed => $values[$index])->all(),
    ]));
}

function weekWindow(): TrendWindow
{
    return TrendWindow::endingAt(TrendPeriod::Week, now()->startOfMinute());
}

function weekStats(TrendScope $scope = TrendScope::Windows)
{
    return app(App\Queries\TrendQueries::class)->deviceStats(weekWindow(), $scope);
}

function completedRun(Device $device, ?Script $script, array $attributes = []): DeviceCommand
{
    return DeviceCommand::factory()->create([
        'device_id' => $device->id,
        'script_id' => $script?->id,
        'status' => CommandStatus::Completed,
        'exit_code' => 0,
        'completed_at' => now()->subDay(),
        ...$attributes,
    ]);
}

it('averages each period separately and splits at the start of the latest one', function (): void {
    $device = Device::factory()->active()->windows()->create();
    trendReports($device, '2026-09-25 09:00', ['ram' => [40.0, 42.0, 41.0], 'cpu' => [10.0, 20.0, 30.0], 'swap_used_mib' => [1000.0, 2000.0, 3000.0], 'disk_busy_percent' => [5.0, 5.0, 8.0]]);
    trendReports($device, '2026-10-06 09:00', ['ram' => [36.0, 38.0, 37.0], 'cpu' => [40.0, 50.0, 60.0], 'swap_used_mib' => [500.0, 500.0, 500.0], 'disk_busy_percent' => [2.0, 2.0, 2.0]]);
    trendReports($device, '2026-09-20 09:00', ['ram' => [99.0, 99.0, 99.0]]);

    $stats = weekStats()->get($device->id);

    expect($stats->previous)
        ->reports->toBe(3)
        ->ram->toEqualWithDelta(41.0, 0.001)
        ->cpu->toEqualWithDelta(20.0, 0.001)
        ->swapMib->toEqualWithDelta(2000.0, 0.001)
        ->diskBusy->toEqualWithDelta(6.0, 0.001)
        ->and($stats->current)
        ->ram->toEqualWithDelta(37.0, 0.001)
        ->cpu->toEqualWithDelta(50.0, 0.001);
});

it('works out the fleet change, its percentage and which way is better', function (): void {
    $device = Device::factory()->active()->windows()->create();
    trendReports($device, '2026-09-25 09:00', ['ram' => [41.0, 41.0, 41.0], 'cpu' => [10.0, 10.0, 10.0]]);
    trendReports($device, '2026-10-06 09:00', ['ram' => [37.0, 37.0, 37.0], 'cpu' => [20.0, 20.0, 20.0]]);

    $fleet = FleetTrend::from(weekStats());

    expect($fleet->isComparable)->toBeTrue()
        ->and($fleet->change(TrendMetric::Ram))
        ->previousForHumans()->toBe('41%')
        ->currentForHumans()->toBe('37%')
        ->differenceForHumans()->toBe('−10%')
        ->direction()->toBe(TrendDirection::Improved)
        ->and($fleet->change(TrendMetric::Cpu))
        ->differenceForHumans()->toBe('+100%')
        ->direction()->toBe(TrendDirection::Worse);
});

it('reads small moves as no real change, and hours online as neither better nor worse', function (): void {
    expect(new MetricChange(TrendMetric::Ram, 41.0, 41.5))->direction()->toBe(TrendDirection::Steady)
        ->and(new MetricChange(TrendMetric::OnlineHours, 40.0, 60.0))->direction()->toBe(TrendDirection::Changed)
        ->and(new MetricChange(TrendMetric::Reboots, 0.0, 3.0))->differenceForHumans()->toBe('+3')
        ->and(new MetricChange(TrendMetric::Reboots, 0.0, 0.0))->direction()->toBe(TrendDirection::Steady)
        ->and(new MetricChange(TrendMetric::BlankRate, 5.0, 1.5))->differenceForHumans()->toBe('−3.5 pts')
        ->and(new MetricChange(TrendMetric::DiskBusy, 0.0, 4.0))->differenceForHumans()->toBe('+4%')
        ->and(new MetricChange(TrendMetric::Ram, null, 40.0))->direction()->toBe(TrendDirection::Unmeasured)
        ->and(new MetricChange(TrendMetric::Ram, null, 40.0))->differenceForHumans()->toBeNull();
});

it('takes the nearest-rank 95th percentile of CPU and leaves blank reports out of it', function (): void {
    $device = Device::factory()->active()->windows()->create();
    trendReports($device, '2026-10-06 09:00', ['cpu' => [...range(1, 20), null]], everyMinutes: 1);

    expect(weekStats()->get($device->id)->current->cpuPeak)->toEqualWithDelta(19.0, 0.001);
});

it('counts reports with no CPU as the blank rate, per device and across the fleet', function (): void {
    $first = Device::factory()->active()->windows()->create();
    $second = Device::factory()->active()->windows()->create();
    trendReports($first, '2026-09-25 09:00', ['cpu' => [null, 10.0, 10.0, 10.0]]);
    trendReports($first, '2026-10-06 09:00', ['cpu' => [10.0, 10.0, 10.0, 10.0]]);
    trendReports($second, '2026-09-25 09:00', ['cpu' => [null, null, null, 10.0, 10.0, 10.0]]);
    trendReports($second, '2026-10-06 09:00', ['cpu' => [null, 10.0, 10.0, 10.0, 10.0, 10.0]]);

    $stats = weekStats();

    expect($stats->get($first->id)->previous->blankRate())->toBe(25.0)
        ->and(FleetTrend::from($stats)->change(TrendMetric::BlankRate))
        ->previous->toBe(40.0)
        ->current->toEqualWithDelta(10.0, 0.001)
        ->direction()->toBe(TrendDirection::Improved);
});

it('counts a reboot each time uptime resets, ignoring small clock jitter', function (): void {
    $device = Device::factory()->active()->windows()->create();
    trendReports($device, '2026-10-06 09:00', ['uptime_seconds' => [900000, 900300, 120, 420, 410, 720, 30]]);
    trendReports($device, '2026-09-25 09:00', ['uptime_seconds' => [5000, 5300, 5600]]);

    $stats = weekStats()->get($device->id);

    expect($stats->current->reboots)->toBe(2)
        ->and($stats->previous->reboots)->toBe(0)
        ->and(FleetTrend::from(weekStats())->change(TrendMetric::Reboots)->differenceForHumans())->toBe('+2');
});

it('counts hours online as the five-minute slots holding a report', function (): void {
    $device = Device::factory()->active()->windows()->create();
    trendReports($device, '2026-10-06 09:00', ['cpu' => array_fill(0, 24, 10.0)]);
    trendReports($device, '2026-10-06 15:00', ['cpu' => array_fill(0, 10, 10.0)], everyMinutes: 1);

    expect(weekStats()->get($device->id)->current->onlineHours)->toEqualWithDelta(2.0 + 2 * 5 / 60, 0.001);
});

it('compares the fleet over devices with enough reports in both periods only', function (): void {
    $steady = Device::factory()->active()->windows()->create();
    $newcomer = Device::factory()->active()->windows()->create();
    trendReports($steady, '2026-09-25 09:00', ['ram' => [40.0, 40.0, 40.0]]);
    trendReports($steady, '2026-10-06 09:00', ['ram' => [30.0, 30.0, 30.0]]);
    trendReports($newcomer, '2026-10-06 09:00', ['ram' => [90.0, 90.0, 90.0]]);
    trendReports($newcomer, '2026-09-25 09:00', ['ram' => [90.0, 90.0]]);

    $stats = weekStats();
    $fleet = FleetTrend::from($stats);

    expect($fleet->deviceIds)->toBe([$steady->id])
        ->and($fleet->change(TrendMetric::Ram)->current)->toBe(30.0)
        ->and($stats->get($newcomer->id)->isComparable())->toBeFalse()
        ->and($stats->get($newcomer->id)->change(TrendMetric::Ram)->previous)->toBeNull();
});

it('shows the latest period alone when nothing has enough history before it', function (): void {
    $device = Device::factory()->active()->windows()->create();
    trendReports($device, '2026-10-06 09:00', ['ram' => [30.0, 30.0, 30.0]]);

    $fleet = FleetTrend::from(weekStats());

    expect($fleet->isComparable)->toBeFalse()
        ->and($fleet->deviceIds)->toBe([$device->id])
        ->and($fleet->change(TrendMetric::Ram))
        ->previous->toBeNull()
        ->current->toBe(30.0)
        ->direction()->toBe(TrendDirection::Unmeasured);
});

it('measures Windows PCs by default and every approved device when asked', function (): void {
    $pc = Device::factory()->active()->windows()->create();
    $server = Device::factory()->active()->monitorOnly()->create(['kernel_name' => 'Linux']);
    $pending = Device::factory()->windows()->create();
    collect([$pc, $server, $pending])->each(fn (Device $device) => trendReports($device, '2026-10-06 09:00', ['ram' => [30.0, 30.0, 30.0]]));

    expect(weekStats()->keys()->all())->toBe([$pc->id])
        ->and(weekStats(TrendScope::All)->keys()->sort()->values()->all())->toBe([$pc->id, $server->id]);
});

it('overlays the previous period on the latest in buckets sized by the period', function (): void {
    $device = Device::factory()->active()->windows()->create();
    trendReports($device, '2026-10-06 11:01', ['ram' => [40.0, 50.0]]);
    trendReports($device, '2026-10-07 11:01', ['ram' => [30.0, 31.0]]);
    trendReports($device, '2026-10-07 12:10', ['ram' => [20.0, 20.0]]);

    $series = app(App\Queries\TrendQueries::class)->fleetSeries(TrendWindow::endingAt(TrendPeriod::Day, now()), [$device->id]);

    expect($series)->toHaveCount(96)
        ->and($series->first())->toBe(['time' => '2026-10-06T12:00:00Z', 'ram' => null, 'previousRam' => null, 'cpu' => null, 'previousCpu' => null])
        ->and($series->firstWhere('time', '2026-10-07T11:00:00Z'))->toMatchArray(['ram' => 30.5, 'previousRam' => 45.0])
        ->and($series->last()['time'])->toBe('2026-10-07T11:45:00Z');
});

it('caches the comparison briefly', function (): void {
    $device = Device::factory()->active()->windows()->create();
    trendReports($device, '2026-10-06 09:00', ['ram' => [30.0, 30.0, 30.0]]);

    $first = app(CompareTrendPeriods::class)(TrendPeriod::Week, TrendScope::Windows);
    trendReports($device, '2026-10-06 10:00', ['ram' => [90.0, 90.0, 90.0]]);

    expect(app(CompareTrendPeriods::class)(TrendPeriod::Week, TrendScope::Windows)->stats->get($device->id)->current->reports)->toBe($first->stats->get($device->id)->current->reports);

    $this->travel(config('trends.cache_seconds') + 1)->seconds();

    expect(app(CompareTrendPeriods::class)(TrendPeriod::Week, TrendScope::Windows)->stats->get($device->id)->current->reports)->toBe(6);
});

describe('changes in the same period', function (): void {
    beforeEach(function (): void {
        app(SyncSystemScripts::class)();
    });

    it('groups package changes by action and package, counted across devices', function (): void {
        $pcs = Device::factory()->active()->windows()->count(3)->create();
        $uninstall = Script::findSystem('winget-uninstall');
        $pcs->each(fn (Device $pc) => completedRun($pc, $uninstall, ['parameters' => ['PackageId' => 'Datadog.Agent', 'CloseApp' => false]]));
        completedRun($pcs->first(), $uninstall, ['parameters' => ['PackageId' => 'Datadog.Agent'], 'completed_at' => now()->subHours(2)]);
        completedRun($pcs->first(), Script::findSystem('winget-install'), ['parameters' => ['PackageId' => 'Mozilla.Firefox']]);
        DeviceSoftware::factory()->create(['package_id' => 'Mozilla.Firefox', 'name' => 'Mozilla Firefox']);

        $changes = app(App\Queries\TrendQueries::class)->changes(weekWindow(), TrendScope::Windows)->keyBy('key');

        $datadog = $changes->get('winget-uninstall|Datadog.Agent');

        expect($changes)->toHaveCount(2)
            ->and($datadog->kind)->toBe(TrendChangeKind::Uninstalled)
            ->and($datadog->summary())->toBe('Datadog.Agent uninstalled on 3 PCs')
            ->and($datadog->runCount)->toBe(4)
            ->and($datadog->labelFor($pcs->first()->id))->toBe('Uninstalled Datadog.Agent ×2')
            ->and($datadog->href)->toBe(route('software.show', ['id' => 'Datadog.Agent']))
            ->and($changes->get('winget-install|Mozilla.Firefox')->summary())->toBe('Mozilla Firefox installed on 1 PC');
    });

    it('groups scripts by script and ad-hoc commands together, newest first, and flags scheduled runs', function (): void {
        $pcs = Device::factory()->active()->windows()->count(2)->create();
        $task = ScheduledTask::factory()->create();
        completedRun($pcs[0], Script::findSystem('debloat-windows'), ['completed_at' => now()->subDays(3)]);
        completedRun($pcs[1], Script::findSystem('debloat-windows'), ['completed_at' => now()->subDays(2)]);
        completedRun($pcs[0], Script::findSystem('restart'), ['scheduled_task_id' => $task->id, 'completed_at' => now()->subHours(3)]);
        completedRun($pcs[1], null, ['script_content' => 'Get-Process', 'completed_at' => now()->subDays(4)]);

        $changes = app(App\Queries\TrendQueries::class)->changes(weekWindow(), TrendScope::Windows);

        expect($changes->map(fn (TrendChange $change): string => $change->summary())->all())->toBe([
            'Restart ran on 1 PC',
            'Debloat Windows ran on 2 PCs',
            'Ad-hoc commands ran on 1 PC',
        ])
            ->and($changes->first()->isScheduled)->toBeTrue()
            ->and($changes->get(1)->isScheduled)->toBeFalse()
            ->and($changes->get(1)->href)->toBe(route('scripts.show', Script::findSystem('debloat-windows')->id))
            ->and($changes->get(1)->datesForHumans())->toBe('4 Oct – 5 Oct');
    });

    it('leaves out failed runs, read-only reports, runs outside the period and devices outside the scope', function (): void {
        $pc = Device::factory()->active()->windows()->create();
        $pending = Device::factory()->windows()->create();
        completedRun($pc, Script::findSystem('disk-cleanup'), ['status' => CommandStatus::Failed, 'exit_code' => 1]);
        completedRun($pc, Script::findSystem('disk-cleanup'), ['exit_code' => 1]);
        completedRun($pc, Script::findSystem('winget-inventory'));
        completedRun($pc, Script::findSystem('disk-cleanup'), ['completed_at' => now()->subDays(8)]);
        completedRun($pending, Script::findSystem('disk-cleanup'));
        DeviceCommand::factory()->pending()->create(['device_id' => $pc->id, 'script_id' => Script::findSystem('disk-cleanup')->id]);

        expect(app(App\Queries\TrendQueries::class)->changes(weekWindow(), TrendScope::Windows))->toBeEmpty();
    });
});
