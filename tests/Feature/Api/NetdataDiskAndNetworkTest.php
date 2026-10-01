<?php

declare(strict_types=1);

use App\DTOs\NetdataV3Metrics;
use App\Livewire\Devices\Show;
use App\Models\Device;
use App\Models\DeviceDiskMetric;
use App\Models\DeviceMetric;
use App\Models\DeviceNetworkMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

/**
 * Shapes and values captured from a real agent's Netdata (DESKTOP-5ULJ14E, 1 Oct 2026).
 */
function netdataResponse(array $dimensions, string $units): array
{
    return [
        'view' => [
            'units' => $units,
            'dimensions' => [
                'ids' => array_keys($dimensions),
                'sts' => ['avg' => array_values($dimensions)],
            ],
        ],
        'result' => [
            'labels' => ['time', ...array_keys($dimensions)],
            'data' => [[1790867400, ...array_values($dimensions)]],
        ],
    ];
}

function diskByVolume(): array
{
    $node = '5fb86e46-890a-af4f-afde-034c792a2a78';

    return netdataResponse([
        "avail,disk_space.HarddiskVolume1@{$node}" => 0.1875,
        "used,disk_space.HarddiskVolume1@{$node}" => 0.1015625,
        "avail,disk_space.C:@{$node}" => 116.0282881,
        "used,disk_space.C:@{$node}" => 119.5390912,
        "avail,disk_space.HarddiskVolume4@{$node}" => 0.2099609,
        "used,disk_space.HarddiskVolume4@{$node}" => 0.7558594,
        "avail,disk_space.HarddiskVolume5@{$node}" => 0.4404297,
        "used,disk_space.HarddiskVolume5@{$node}" => 1.0634766,
    ], 'GiB');
}

function diskAveragedAcrossVolumes(): array
{
    return netdataResponse(['avail' => 29.2170344, 'used' => 30.3645076], 'GiB');
}

function networkTotals(): array
{
    return netdataResponse(['received' => 12.9860626, 'sent' => -4.9928311], 'kilobits/s');
}

it('splits disk space into one row per volume', function (): void {
    $volumes = (new NetdataV3Metrics(diskByVolume()))->parseDiskVolumes();

    expect(collect($volumes)->pluck('mount_point')->all())
        ->toBe(['HarddiskVolume1', 'C:', 'HarddiskVolume4', 'HarddiskVolume5']);

    expect(collect($volumes)->firstWhere('mount_point', 'C:'))->toBe([
        'mount_point' => 'C:',
        'used_gb' => 119.54,
        'available_gb' => 116.03,
        'total_gb' => 235.57,
        'usage_percent' => 50.75,
    ]);
});

it('skips ignored volumes such as recovery partitions', function (): void {
    $volumes = (new NetdataV3Metrics(diskByVolume()))->parseDiskVolumes(config('devices.disk.ignored_volumes'));

    expect(collect($volumes)->pluck('mount_point')->all())->toBe(['C:']);
});

it('refuses disk data that netdata averaged across every volume', function (): void {
    expect((new NetdataV3Metrics(diskAveragedAcrossVolumes()))->parseDiskVolumes())->toBe([]);
});

it('reads machine-wide network throughput with sent as a positive number', function (): void {
    expect((new NetdataV3Metrics(networkTotals()))->parseNetworkTotals())
        ->toBe(['received_kbps' => 12.99, 'sent_kbps' => 4.99]);

    expect((new NetdataV3Metrics(diskByVolume()))->parseNetworkTotals())->toBeNull();
});

it('stores per-volume disk and network rows from a raw agent payload', function (): void {
    Device::factory()->withApiKey('KEY-DISK')->create();

    $this->withHeaders(['X-Agent-Key' => 'KEY-DISK'])
        ->postJson('/api/metrics', [
            'hostname' => 'DESKTOP-5ULJ14E',
            'timestamp' => now()->toIso8601String(),
            'agent_version' => '0.5.1',
            'netdata_disk' => diskByVolume(),
            'netdata_net' => networkTotals(),
        ])
        ->assertSuccessful();

    $metric = DeviceMetric::sole();

    expect(DeviceDiskMetric::sole())
        ->device_metric_id->toBe($metric->id)
        ->mount_point->toBe('C:')
        ->usage_percent->toEqual(50.75);

    expect(DeviceNetworkMetric::sole())
        ->interface->toBe('total')
        ->received_kbps->toEqual(12.99)
        ->sent_kbps->toEqual(4.99);
});

it('stores no disk rows from an old agent that sends averaged disk data', function (): void {
    Device::factory()->withApiKey('KEY-OLD')->create();

    $this->withHeaders(['X-Agent-Key' => 'KEY-OLD'])
        ->postJson('/api/metrics', [
            'hostname' => 'OLD-AGENT',
            'agent_version' => '0.5.0',
            'netdata_disk' => diskAveragedAcrossVolumes(),
        ])
        ->assertSuccessful();

    expect(DeviceDiskMetric::count())->toBe(0);
});

it('shows reported volumes on the device page', function (): void {
    $device = Device::factory()->active()->create(['disks' => null]);
    $metric = DeviceMetric::factory()->create(['device_id' => $device->id, 'recorded_at' => now()]);
    $metric->recordDisks((new NetdataV3Metrics(diskByVolume()))->parseDiskVolumes(config('devices.disk.ignored_volumes')));

    Livewire::actingAs(User::factory()->create())
        ->test(Show::class, ['device' => $device])
        ->assertSee('Disk Storage')
        ->assertSee('C:')
        ->assertSee('116.0 GB free of 235.6 GB')
        ->assertDontSee('HarddiskVolume1');
});
