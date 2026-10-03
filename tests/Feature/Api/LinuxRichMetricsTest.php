<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->device = Device::factory()->monitorOnly()->withApiKey('RICH-KEY')->create();
});

/**
 * A 0.7.1 Linux report with every new field, overridable per test.
 */
function richLinuxReport(array $overrides = []): array
{
    return [
        'monitor_only' => true,
        'agent_version' => '0.7.1',
        'cpu' => ['usage_percent' => 12],
        'memory' => ['usage_percent' => 40, 'used_mib' => 1600, 'total_mib' => 4000],
        'load' => ['load1' => 0.5, 'load5' => 0.4, 'load15' => 0.3],
        'processes' => ['total' => 180],
        'swap' => ['used_mib' => 256, 'total_mib' => 1024],
        'disks' => [
            ['mount_point' => '/', 'filesystem' => 'ext4', 'total_gb' => 100, 'available_gb' => 40, 'read_kbps' => 120.5, 'write_kbps' => 64, 'utilization_percent' => 7.5, 'inode_usage_percent' => 12.5],
            ['mount_point' => '/srv', 'filesystem' => 'xfs', 'total_gb' => 200, 'available_gb' => 150, 'read_kbps' => 10, 'write_kbps' => 5, 'utilization_percent' => 31.25],
            ['mount_point' => '/run/lock', 'filesystem' => 'tmpfs', 'total_gb' => 1, 'available_gb' => 1, 'inode_usage_percent' => 1],
        ],
        'network' => [
            ['interface' => 'eth0', 'received_kbps' => 850, 'sent_kbps' => 120, 'received_errors' => 3, 'sent_errors' => 0, 'received_drops' => 12, 'sent_drops' => 1, 'link_speed_mbps' => 10000],
            ['interface' => 'lo', 'received_kbps' => 999, 'sent_kbps' => 999],
            ['interface' => 'veth1a2b3c', 'received_kbps' => 5, 'sent_kbps' => 5],
        ],
        'apps' => [
            ['name' => 'postgres', 'cpu_percent' => 22.5, 'memory_mib' => 812.25],
            ['name' => 'nginx', 'cpu_percent' => 1.25, 'memory_mib' => 64],
        ],
        'linux_health' => [
            'failed_units' => ['backup.service', 'certbot.timer'],
            'reboot_required' => true,
            'pending_updates' => 14,
            'pending_security_updates' => 3,
            'checked_updates_at' => '2026-10-03T09:15:00Z',
        ],
        ...$overrides,
    ];
}

function postRichLinux(array $report): Illuminate\Testing\TestResponse
{
    return test()->withHeaders(['X-Device-Key' => 'RICH-KEY'])->postJson('/api/metrics', $report);
}

it('stores swap, disk I/O, inodes, adapter health, apps and linux health from a 0.7.1 report', function (): void {
    postRichLinux(richLinuxReport())->assertSuccessful();

    $metric = DeviceMetric::query()->with(['diskMetrics', 'networkMetrics', 'appMetrics'])->sole();
    $root = $metric->diskMetrics->firstWhere('mount_point', '/');
    $adapter = $metric->networkMetrics->sole();

    expect($metric)
        ->swap_used_mib->toBe(256.0)
        ->swap_total_mib->toBe(1024.0)
        ->processes_total->toBe(180)
        ->disk_busy_percent->toBe(31.25)
        ->failed_units->toBe(['backup.service', 'certbot.timer'])
        ->reboot_required->toBeTrue()
        ->pending_updates->toBe(14)
        ->pending_security_updates->toBe(3)
        ->and($metric->updates_checked_at->equalTo(Carbon::parse('2026-10-03T09:15:00Z')))->toBeTrue()
        ->and($metric->diskMetrics->pluck('mount_point')->all())->toBe(['/', '/srv'])
        ->and($root)
        ->read_kbps->toEqual(120.5)
        ->write_kbps->toEqual(64)
        ->inode_usage_percent->toEqual(12.5)
        ->and($adapter)
        ->interface->toBe('eth0')
        ->received_kbps->toEqual(850)
        ->errors_inbound->toEqual(3)
        ->errors_outbound->toEqual(0)
        ->drops_inbound->toEqual(12)
        ->drops_outbound->toEqual(1)
        ->link_speed_kbps->toEqual(10000000)
        ->and($metric->appMetrics->pluck('name')->all())->toBe(['postgres', 'nginx']);
});

it('keeps only the configured number of top apps by CPU and by memory', function (): void {
    config(['devices.metrics.top_apps' => 1]);

    postRichLinux(richLinuxReport(['apps' => [
        ['name' => 'busy', 'cpu_percent' => 90, 'memory_mib' => 10],
        ['name' => 'hungry', 'cpu_percent' => 1, 'memory_mib' => 9000],
        ['name' => 'idle', 'cpu_percent' => 0.5, 'memory_mib' => 5],
    ]]))->assertSuccessful();

    expect(DeviceMetric::query()->sole()->appMetrics->pluck('name')->all())->toBe(['busy', 'hungry']);
});

it('keeps working for older agents that send none of the new fields', function (): void {
    postRichLinux(['agent_version' => '0.7.0', 'monitor_only' => true, 'cpu' => ['usage_percent' => 5], 'disks' => [['mount_point' => '/', 'total_gb' => 10, 'available_gb' => 5]]])
        ->assertSuccessful();

    expect(DeviceMetric::query()->sole())
        ->swap_total_mib->toBeNull()
        ->disk_busy_percent->toBeNull()
        ->failed_units->toBeNull()
        ->reboot_required->toBeNull()
        ->pending_updates->toBeNull()
        ->hasHealthReport->toBeFalse();
});

it('treats failed units left out as unknown, not as all running', function (): void {
    postRichLinux(richLinuxReport(['linux_health' => ['reboot_required' => false, 'pending_updates' => null]]))->assertSuccessful();

    expect(DeviceMetric::query()->sole())
        ->failed_units->toBeNull()
        ->reboot_required->toBeFalse()
        ->hasFailedUnits->toBeFalse();
});

it('rejects malformed new fields with readable messages', function (array $overrides, string $field, string $message): void {
    postRichLinux(richLinuxReport($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'inodes over 100' => [['disks' => [['mount_point' => '/', 'inode_usage_percent' => 140]]], 'disks.0.inode_usage_percent', 'Inode usage cannot be above 100%.'],
    'negative errors' => [['network' => [['interface' => 'eth0', 'received_errors' => -1]]], 'network.0.received_errors', 'Network error counts cannot be negative.'],
    'link speed text' => [['network' => [['interface' => 'eth0', 'link_speed_mbps' => 'fast']]], 'network.0.link_speed_mbps', 'Link speed must be a number of Mbps.'],
    'swap negative' => [['swap' => ['used_mib' => -5, 'total_mib' => 10]], 'swap.used_mib', 'Swap used cannot be negative.'],
    'app without a name' => [['apps' => [['cpu_percent' => 3]]], 'apps.0.name', 'Every app needs a name.'],
    'units not a list' => [['linux_health' => ['failed_units' => 'backup.service']], 'linux_health.failed_units', 'Failed units must be a list of unit names.'],
    'reboot not boolean' => [['linux_health' => ['reboot_required' => 'maybe']], 'linux_health.reboot_required', 'Reboot required must be true or false.'],
    'updates fractional' => [['linux_health' => ['pending_updates' => 2.5]], 'linux_health.pending_updates', 'Pending updates must be a whole number.'],
    'security negative' => [['linux_health' => ['pending_security_updates' => -1]], 'linux_health.pending_security_updates', 'Pending security updates cannot be negative.'],
    'checked not a date' => [['linux_health' => ['checked_updates_at' => 'yesterday-ish']], 'linux_health.checked_updates_at', 'The update check time must be an RFC 3339 date.'],
]);

it('names swap per platform', function (string $state, string $label, string $missing): void {
    $device = Device::factory()->{$state}()->make();

    expect($device->swapLabel())->toBe($label)
        ->and($device->platform()->missingSwapLabel())->toBe($missing);
})->with([
    'windows' => ['windows', 'Page File', '—'],
    'linux' => ['linux', 'Swap', 'No swap'],
]);
