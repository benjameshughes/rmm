<?php

declare(strict_types=1);

use App\Enums\MetricSampleType;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\MetricSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->device = Device::factory()->monitorOnly()->withApiKey('LXC-KEY')->create();
});

/**
 * Move every point of a window later, as the next report's window would be.
 */
function shiftedWindow(array $response, int $seconds): array
{
    $response['result']['data'] = collect($response['result']['data'] ?? [])
        ->map(fn (array $row): array => [$row[0] + $seconds, ...array_slice($row, 1)])
        ->all();

    return $response;
}

/**
 * A 0.8.0 Linux report built from the qdrant-test captures, every window
 * moved $shiftSeconds later.
 */
function linuxWindowReport(int $shiftSeconds = 0, array $overrides = []): array
{
    $contexts = [
        'netdata_cpu' => 'system.cpu',
        'netdata_ram' => 'system.ram',
        'netdata_load' => 'system.load',
        'netdata_uptime' => 'system.uptime',
        'netdata_net' => 'system.net',
        'netdata_swap' => 'mem.swap',
        'netdata_processes' => 'system.processes',
        'netdata_disk' => 'disk.space',
        'netdata_disk_inodes' => 'disk.inodes',
        'netdata_disk_util' => 'disk.util',
        'netdata_net_interfaces' => 'net.net',
        'netdata_net_errors' => 'net.errors',
        'netdata_net_drops' => 'net.drops',
        'netdata_net_speed' => 'net.speed',
        'netdata_apps_cpu' => 'app.cpu_utilization',
        'netdata_apps_mem' => 'app.mem_usage',
    ];

    return [
        'hostname' => 'qdrant-test',
        'agent_version' => '0.8.0',
        'monitor_only' => true,
        ...collect($contexts)->map(fn (string $context): array => shiftedWindow(netdataLinuxFixture($context), $shiftSeconds))->all(),
        'linux_health' => [
            'failed_units' => ['backup.service'],
            'reboot_required' => false,
            'pending_updates' => 4,
            'pending_security_updates' => 1,
            'checked_updates_at' => '2026-10-04T08:00:00Z',
        ],
        ...$overrides,
    ];
}

function postLinuxWindow(array $report): Illuminate\Testing\TestResponse
{
    return test()->withHeaders(['X-Device-Key' => 'LXC-KEY'])->postJson('/api/metrics', $report);
}

it('stores a report summarising the whole window', function (): void {
    postLinuxWindow(linuxWindowReport())->assertSuccessful();

    $metric = DeviceMetric::query()->with(['diskMetrics', 'networkMetrics', 'appMetrics'])->sole();
    $root = $metric->diskMetrics->firstWhere('mount_point', '/');
    $adapter = $metric->networkMetrics->sole();

    expect($metric)
        ->cpu->toBe(7.01)
        ->ram->toBe(17.91)
        ->load1->toEqualWithDelta(2.73, 0.01)
        ->uptime_seconds->toBe(2861698)
        ->processes_running->toBe(1)
        ->processes_blocked->toBe(0)
        ->swap_used_mib->toBeNull()
        ->disk_busy_percent->toBe(17.27)
        ->failed_units->toBe(['backup.service'])
        ->pending_updates->toBe(4)
        ->pending_security_updates->toBe(1)
        ->agent_version->toBe('0.8.0')
        ->and($metric->diskMetrics->pluck('mount_point')->all())->toBe(['/', '/tmp'])
        ->and($root)
        ->usage_percent->toEqual(22.14)
        ->inode_usage_percent->toEqual(4.96)
        ->and($adapter)
        ->interface->toBe('eth0')
        ->received_kbps->toEqual(3.21)
        ->sent_kbps->toEqual(0.89)
        ->link_speed_kbps->toEqual(10000000)
        ->and($metric->appMetrics)->not->toBeEmpty()
        ->and($this->device->fresh()->is_monitor_only)->toBeTrue();
});

it('keeps every second of the window as samples', function (): void {
    postLinuxWindow(linuxWindowReport())->assertSuccessful();

    $cpu = MetricSample::query()->where('metric', MetricSampleType::Cpu)->orderByDesc('recorded_at')->get();

    expect(MetricSample::count())->toBe(300)
        ->and(MetricSample::query()->where('metric', MetricSampleType::Swap)->count())->toBe(0)
        ->and($cpu)->toHaveCount(60)
        ->and($cpu->first()->recorded_at->equalTo(Carbon::createFromTimestamp(1791103211)))->toBeTrue()
        ->and($cpu->first()->value)->toBe(6.32)
        ->and($cpu->max('value'))->toBe(49.48)
        ->and(MetricSample::query()->where('metric', MetricSampleType::Load)->max('value'))->toEqual(3.56)
        ->and(MetricSample::query()->where('metric', MetricSampleType::NetworkOut)->min('value'))->toBeGreaterThanOrEqual(0);
});

it('does not duplicate the seconds two overlapping windows share', function (): void {
    postLinuxWindow(linuxWindowReport())->assertSuccessful();
    postLinuxWindow(linuxWindowReport(shiftSeconds: 30))->assertSuccessful();

    expect(DeviceMetric::count())->toBe(2)
        ->and(MetricSample::query()->where('metric', MetricSampleType::Cpu)->count())->toBe(90)
        ->and(MetricSample::count())->toBe(450);
});

it('keeps no samples from a single averaged point', function (): void {
    $device = Device::factory()->withApiKey('KEY-071')->create();

    test()->withHeaders(['X-Device-Key' => 'KEY-071'])
        ->postJson('/api/metrics', ['agent_version' => '0.7.1', ...netdataWindowsFixture(), 'netdata_cpu' => [
            'view' => ['dimensions' => ['ids' => ['user', 'system'], 'sts' => ['avg' => [10.0, 5.0]]]],
            'result' => ['data' => [[1791103211, 10.0, 5.0]]],
        ]])
        ->assertSuccessful();

    expect($device->metrics()->sole()->cpu)->toBe(15.0)
        ->and(MetricSample::count())->toBe(0);
});

it('still stores linux health before netdata is running', function (): void {
    postLinuxWindow([
        'hostname' => 'qdrant-test',
        'agent_version' => '0.8.0',
        'monitor_only' => true,
        'mac_addresses' => ['bc:24:11:aa:bb:cc'],
        'linux_health' => ['failed_units' => [], 'reboot_required' => true, 'pending_updates' => 0],
    ])->assertSuccessful();

    expect(DeviceMetric::query()->sole())
        ->cpu->toBeNull()
        ->reboot_required->toBeTrue()
        ->failed_units->toBe([])
        ->and(MetricSample::count())->toBe(0)
        ->and($this->device->fresh()->mac_addresses)->toBe(['BC:24:11:AA:BB:CC']);
});

it('latches monitor only from a raw netdata report', function (): void {
    $device = Device::factory()->withApiKey('FRESH-LXC')->create(['is_monitor_only' => false]);

    test()->withHeaders(['X-Device-Key' => 'FRESH-LXC'])->postJson('/api/metrics', linuxWindowReport())->assertSuccessful();
    test()->withHeaders(['X-Device-Key' => 'FRESH-LXC'])->postJson('/api/metrics', linuxWindowReport(60, ['monitor_only' => false]))->assertSuccessful();

    expect($device->fresh()->is_monitor_only)->toBeTrue();
});

it('removes samples with their device', function (): void {
    postLinuxWindow(linuxWindowReport())->assertSuccessful();

    $this->device->delete();

    expect(MetricSample::count())->toBe(0);
});
