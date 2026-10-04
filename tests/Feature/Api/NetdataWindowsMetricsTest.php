<?php

declare(strict_types=1);

use App\DTOs\NetdataAppUsage;
use App\DTOs\NetdataDiskMetrics;
use App\DTOs\NetdataNetworkAdapters;
use App\DTOs\NetdataSystemMetrics;
use App\Models\Device;
use App\Models\DeviceAppMetric;
use App\Models\DeviceMetric;
use App\Models\DeviceNetworkMetric;

const INTEL_NIC = 'Intel[R] Ethernet Connection [17] I219-LM';

function ungroupedResponse(array $dimensions): array
{
    return ['view' => ['dimensions' => ['ids' => array_keys($dimensions), 'sts' => ['avg' => array_values($dimensions)]]]];
}

/**
 * The real capture only had one adapter, so a Hyper-V switch is added the way
 * Netdata names one to prove ignored adapters are skipped.
 */
function withVirtualSwitch(array $response, string $prefix, array $dimensions): array
{
    collect($dimensions)->each(function (float $value, string $dimension) use (&$response, $prefix): void {
        $response['view']['dimensions']['ids'][] = "{$dimension},{$prefix}.vEthernet (Default Switch)@5fb86e46-890a-af4f-afde-034c792a2a78";
        $response['view']['dimensions']['sts']['avg'][] = $value;
    });

    return $response;
}

function windowsAgentPayload(array $overrides = []): array
{
    $fixture = netdataWindowsFixture();

    return [
        'hostname' => 'DESKTOP-5ULJ14E',
        'timestamp' => now()->toIso8601String(),
        'agent_version' => '0.6.0',
        ...$fixture,
        'netdata_net_interfaces' => withVirtualSwitch($fixture['netdata_net_interfaces'], 'net', ['received' => 3.5, 'sent' => -1.25]),
        'netdata_net' => ungroupedResponse(['received' => 12.9860626, 'sent' => -4.9928311]),
        ...$overrides,
    ];
}

it('reads the processor queue length', function (): void {
    expect((new NetdataSystemMetrics(netdataWindowsFixture()['netdata_cpu_queue']))->parseCpuQueueLength())->toBe(0.265);
});

it('has no cpu queue for missing or linux load data', function (mixed $response): void {
    expect((new NetdataSystemMetrics($response))->parseCpuQueueLength())->toBeNull();
})->with([
    'missing' => [[]],
    'load average' => [ungroupedResponse(['load1' => 0.5, 'load5' => 0.4, 'load15' => 0.3])],
]);

it('reads page file usage', function (): void {
    expect((new NetdataSystemMetrics(netdataWindowsFixture()['netdata_swap']))->parseSwap())
        ->toBe(['used_mib' => 5474.98, 'total_mib' => 17096.73]);
});

it('has no page file for missing, partial or empty swap data', function (mixed $response): void {
    expect((new NetdataSystemMetrics($response))->parseSwap())->toBeNull();
})->with([
    'missing' => [[]],
    'only free' => [ungroupedResponse(['free' => 1024.0])],
    'no swap configured' => [ungroupedResponse(['free' => 0.0, 'used' => 0.0])],
]);

it('reads the busiest physical disk', function (): void {
    $disks = netdataWindowsFixture()['netdata_disk_util'];
    $disks['view']['dimensions']['ids'][] = 'utilization,disk_util.Disk 1@5fb86e46-890a-af4f-afde-034c792a2a78';
    $disks['view']['dimensions']['sts']['avg'][] = 37.456;

    expect((new NetdataDiskMetrics(netdataWindowsFixture()['netdata_disk_util']))->parseBusiestDiskPercent())->toBe(0.81)
        ->and((new NetdataDiskMetrics($disks))->parseBusiestDiskPercent())->toBe(37.46);
});

it('has no disk busy figure for missing or averaged-together data', function (mixed $response): void {
    expect((new NetdataDiskMetrics($response))->parseBusiestDiskPercent())->toBeNull();
})->with([
    'missing' => [[]],
    'ungrouped' => [ungroupedResponse(['utilization' => 4.2])],
]);

it('merges throughput, errors, drops and link speed per adapter', function (): void {
    $fixture = netdataWindowsFixture();

    $adapters = new NetdataNetworkAdapters(
        $fixture['netdata_net_interfaces'],
        $fixture['netdata_net_errors'],
        $fixture['netdata_net_drops'],
        $fixture['netdata_net_speed'],
    );

    expect($adapters->isReported())->toBeTrue()
        ->and($adapters->parse())->toBe([[
            'interface' => INTEL_NIC,
            'received_kbps' => 14.01,
            'sent_kbps' => 6.88,
            'errors_inbound' => 0.0,
            'errors_outbound' => 0.0,
            'drops_inbound' => 0.0,
            'drops_outbound' => 0.0,
            'link_speed_kbps' => 1000000,
        ]]);
});

it('skips ignored adapters', function (): void {
    $adapters = new NetdataNetworkAdapters(withVirtualSwitch(netdataWindowsFixture()['netdata_net_interfaces'], 'net', ['received' => 3.5, 'sent' => -1.25]));

    expect(collect($adapters->parse())->pluck('interface')->all())->toBe([INTEL_NIC, 'vEthernet (Default Switch)'])
        ->and(collect($adapters->parse(config('devices.network.ignored_interfaces')))->pluck('interface')->all())->toBe([INTEL_NIC]);
});

it('leaves adapter health empty when only throughput is reported', function (): void {
    $row = (new NetdataNetworkAdapters(netdataWindowsFixture()['netdata_net_interfaces']))->parse()[0];

    expect($row)->toMatchArray(['received_kbps' => 14.01, 'errors_inbound' => null, 'drops_outbound' => null, 'link_speed_kbps' => null]);
});

it('falls back to one total row when adapters are missing or averaged together', function (mixed $interfaces): void {
    $adapters = new NetdataNetworkAdapters($interfaces, totals: ungroupedResponse(['received' => 12.9860626, 'sent' => -4.9928311]));

    expect($adapters->isReported())->toBeFalse()
        ->and($adapters->rows())->toBe([['interface' => 'total', 'received_kbps' => 12.99, 'sent_kbps' => 4.99]]);
})->with([
    'missing' => [[]],
    'ungrouped' => [ungroupedResponse(['received' => 14.0, 'sent' => -6.8])],
]);

it('has no network rows at all without any network data', function (): void {
    expect((new NetdataNetworkAdapters([]))->rows())->toBe([]);
});

it('keeps the busiest apps by cpu plus the biggest by memory', function (): void {
    $fixture = netdataWindowsFixture();

    $apps = (new NetdataAppUsage($fixture['netdata_apps_cpu'], $fixture['netdata_apps_mem']))->top(3);

    expect($apps)->toBe([
        ['name' => 'Netdata Agent', 'cpu_percent' => 2.23, 'memory_mib' => 106.82],
        ['name' => 'Dell SupportAssist Remediation', 'cpu_percent' => 1.21, 'memory_mib' => 47.52],
        ['name' => 'Windows Update', 'cpu_percent' => 0.58, 'memory_mib' => 29.89],
        ['name' => 'Microsoft Defender Antivirus Service', 'cpu_percent' => 0.18, 'memory_mib' => 240.27],
        ['name' => 'Dell TechHub', 'cpu_percent' => 0.15, 'memory_mib' => 484.72],
    ]);
});

it('keeps an app reported by only one of the two queries', function (): void {
    $apps = (new NetdataAppUsage([], netdataWindowsFixture()['netdata_apps_mem']))->top(1);

    expect($apps)->toBe([['name' => 'Dell TechHub', 'cpu_percent' => null, 'memory_mib' => 484.72]]);
});

it('has no apps for missing or averaged-together data', function (mixed $cpu, mixed $memory): void {
    expect((new NetdataAppUsage($cpu, $memory))->top(10))->toBe([]);
})->with([
    'missing' => [[], []],
    'ungrouped' => [ungroupedResponse(['user' => 3.1, 'system' => 1.2]), ungroupedResponse(['rss' => 2048.0])],
]);

it('stores windows performance, adapters and top apps from a 0.6.0 agent', function (): void {
    config(['devices.metrics.top_apps' => 3]);
    Device::factory()->withApiKey('KEY-WIN')->create();

    $this->withHeaders(['X-Agent-Key' => 'KEY-WIN'])
        ->postJson('/api/metrics', windowsAgentPayload())
        ->assertSuccessful();

    expect(DeviceMetric::sole())
        ->cpu_queue_length->toBe(0.265)
        ->swap_used_mib->toBe(5474.98)
        ->swap_total_mib->toBe(17096.73)
        ->disk_busy_percent->toBe(0.81);

    expect(DeviceNetworkMetric::sole())
        ->interface->toBe(INTEL_NIC)
        ->received_kbps->toBe(14.01)
        ->sent_kbps->toBe(6.88)
        ->errors_inbound->toBe(0.0)
        ->drops_outbound->toBe(0.0)
        ->link_speed_kbps->toBe(1000000);

    expect(DeviceMetric::sole()->appMetrics->pluck('name')->all())->toBe([
        'Netdata Agent',
        'Dell SupportAssist Remediation',
        'Windows Update',
        'Microsoft Defender Antivirus Service',
        'Dell TechHub',
    ]);
});

it('stores the old total network row for agents older than 0.6.0', function (): void {
    Device::factory()->withApiKey('KEY-051')->create();

    $this->withHeaders(['X-Agent-Key' => 'KEY-051'])
        ->postJson('/api/metrics', [
            'hostname' => 'DESKTOP-5ULJ14E',
            'agent_version' => '0.5.1',
            'netdata_net' => ungroupedResponse(['received' => 12.9860626, 'sent' => -4.9928311]),
        ])
        ->assertSuccessful();

    expect(DeviceNetworkMetric::sole())
        ->interface->toBe('total')
        ->received_kbps->toBe(12.99)
        ->link_speed_kbps->toBeNull();

    expect(DeviceMetric::sole())
        ->cpu_queue_length->toBeNull()
        ->swap_total_mib->toBeNull()
        ->disk_busy_percent->toBeNull()
        ->and(DeviceAppMetric::count())->toBe(0);
});

it('stores no total row once adapters are reported, even if all are ignored', function (): void {
    Device::factory()->withApiKey('KEY-VIRTUAL')->create();
    $onlyVirtual = withVirtualSwitch(ungroupedResponse([]), 'net', ['received' => 3.5, 'sent' => -1.25]);

    $this->withHeaders(['X-Agent-Key' => 'KEY-VIRTUAL'])
        ->postJson('/api/metrics', windowsAgentPayload(['netdata_net_interfaces' => $onlyVirtual]))
        ->assertSuccessful();

    expect(DeviceNetworkMetric::count())->toBe(0);
});

it('rejects new netdata fields that are not objects', function (): void {
    Device::factory()->withApiKey('KEY-BAD')->create();

    $this->withHeaders(['X-Agent-Key' => 'KEY-BAD'])
        ->postJson('/api/metrics', ['hostname' => 'BAD', 'netdata_apps_cpu' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('netdata_apps_cpu');
});
