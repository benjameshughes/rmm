<?php

declare(strict_types=1);

use App\DTOs\NetdataDiskMetrics;
use App\DTOs\NetdataSystemMetrics;
use App\DTOs\NetdataV3Metrics;

/**
 * Blank out a fixture's newest $count points the way Netdata reports gaps.
 */
function withGaps(array $response, int $count): array
{
    $response['result']['data'] = collect($response['result']['data'])
        ->map(fn (array $row, int $index): array => $index < $count ? [$row[0], ...array_fill(0, count($row) - 1, null)] : $row)
        ->all();

    return $response;
}

it('averages linux cpu over the whole window by summing every returned dimension', function (): void {
    expect((new NetdataSystemMetrics(netdataLinuxFixture('system.cpu')))->parseCpuUsage())->toBe(7.01);
});

it('keeps each second of linux cpu so the peak survives the average', function (): void {
    $series = (new NetdataSystemMetrics(netdataLinuxFixture('system.cpu')))->cpuUsageSeries();

    expect($series)->toHaveCount(60)
        ->and($series->max())->toBe(49.48)
        ->and($series->search(49.48))->toBe(1791103152)
        ->and($series->keys()->first())->toBe(1791103211)
        ->and($series->first())->toBe(6.32);
});

it('treats a window as newest first whatever order the rows arrive in', function (): void {
    $response = netdataLinuxFixture('system.uptime');
    $response['result']['data'] = array_reverse($response['result']['data']);
    expect((new NetdataV3Metrics($response))->rows()->keys()->first())->toBe(1791103211)
        ->and((new NetdataSystemMetrics($response))->parseUptime())->toBe(2861698.0);
});

it('ignores gaps in the window', function (): void {
    $complete = new NetdataSystemMetrics(netdataLinuxFixture('system.cpu'));
    $gappy = new NetdataSystemMetrics(withGaps(netdataLinuxFixture('system.cpu'), 10));
    $expected = round($complete->cpuUsageSeries()->slice(10)->avg(), 2);

    expect($gappy->cpuUsageSeries())->toHaveCount(50)
        ->and($gappy->cpuUsageSeries()->keys()->first())->toBe(1791103201)
        ->and($gappy->parseCpuUsage())->toBe($expected);
});

it('takes uptime from the newest point that is not a gap', function (): void {
    expect((new NetdataSystemMetrics(withGaps(netdataLinuxFixture('system.uptime'), 2)))->parseUptime())->toBe(2861696.0);
});

it('works out linux ram usage from free, used, cached and buffers', function (): void {
    $metrics = new NetdataSystemMetrics(netdataLinuxFixture('system.ram'));

    expect($metrics->parseRamUsage())->toBe(17.91)
        ->and($metrics->ramUsageSeries())->toHaveCount(60)
        ->and($metrics->getMemoryDetails()['cached_mib'])->toEqualWithDelta(829.49472, 0.0001);
});

it('reads linux load averages from system.load', function (): void {
    $metrics = new NetdataSystemMetrics(netdataLinuxFixture('system.load'));

    expect($metrics->parseLoadAverages())->toEqualWithDelta(['load1' => 2.7337692, 'load5' => 2.1025513, 'load15' => 1.7805], 0.000001)
        ->and((new NetdataV3Metrics(netdataLinuxFixture('system.load')))->dimensionSeries('load1')->max())->toBe(3.56);
});

it('reads running and blocked processes at the newest point', function (): void {
    expect((new NetdataSystemMetrics(netdataLinuxFixture('system.processes')))->parseProcesses())->toBe(['running' => 1, 'blocked' => 0]);
});

it('reads linux disk space per mount from the newest point, leaving out space reserved for root', function (): void {
    $volumes = collect((new NetdataDiskMetrics(netdataLinuxFixture('disk.space')))->parseDiskVolumes());

    expect($volumes->pluck('mount_point')->all())->toBe(['/', '/run', '/tmp'])
        ->and($volumes->firstWhere('mount_point', '/'))->toBe([
            'mount_point' => '/',
            'used_gb' => 1.63,
            'available_gb' => 5.73,
            'total_gb' => 7.36,
            'usage_percent' => 22.14,
        ]);
});

it('reads inode usage per mount', function (): void {
    $inodes = (new NetdataDiskMetrics(netdataLinuxFixture('disk.inodes')))->parseInodeUsage();

    expect($inodes->keys()->all())->toBe(['/', '/run', '/tmp'])
        ->and($inodes->get('/'))->toBe(4.96);
});

it('has no inode usage without a response', function (): void {
    expect((new NetdataDiskMetrics([]))->parseInodeUsage())->toBeEmpty();
});

it('reads the busiest linux disk over the window', function (): void {
    expect((new NetdataDiskMetrics(netdataLinuxFixture('disk.util')))->parseBusiestDiskPercent())->toBe(17.27);
});

it('reports network throughput as positive numbers', function (): void {
    $response = netdataLinuxFixture('system.net');

    expect((new NetdataV3Metrics($response))->dimensionSeries('sent')->every(fn (float $kbps): bool => $kbps >= 0))->toBeTrue()
        ->and((new NetdataSystemMetrics($response))->parseNetworkTotals())->toBe(['received_kbps' => 3.41, 'sent_kbps' => 0.99]);
});

it('is not a window for a single averaged point or a summary alone', function (array $response): void {
    expect((new NetdataV3Metrics($response))->isWindow())->toBeFalse();
})->with([
    'single point' => [['view' => ['dimensions' => ['ids' => ['user'], 'sts' => ['avg' => [5.0]]]], 'result' => ['data' => [[1791103211, 5.0]]]]],
    'summary only' => [['view' => ['dimensions' => ['ids' => ['user'], 'sts' => ['avg' => [5.0]]]]]],
    'no data' => [netdataLinuxFixture('mem.swap')],
]);

it('falls back to netdata\'s own average when a response carries no rows', function (): void {
    $metrics = new NetdataSystemMetrics(['view' => ['dimensions' => ['ids' => ['user', 'system', 'idle'], 'sts' => ['avg' => [10.0, 5.0, 85.0]]]]]);

    expect($metrics->parseCpuUsage())->toBe(15.0)
        ->and($metrics->cpuUsageSeries())->toBeEmpty();
});

it('skips rows whose width does not match the dimensions', function (): void {
    $response = ['view' => ['dimensions' => ['ids' => ['user'], 'sts' => ['avg' => [5.0]]]], 'result' => ['data' => [[1791103211, 1.0, 2.0], 'junk']]];

    expect((new NetdataV3Metrics($response))->rows())->toBeEmpty()
        ->and((new NetdataSystemMetrics($response))->parseCpuUsage())->toBe(5.0);
});
