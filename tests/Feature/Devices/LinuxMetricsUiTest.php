<?php

declare(strict_types=1);

use App\DTOs\DeviceAttention;
use App\DTOs\MetricChart;
use App\Enums\MetricRange;
use App\Livewire\Dashboard;
use App\Livewire\Devices\Apps;
use App\Livewire\Devices\Details;
use App\Livewire\Devices\Metrics;
use App\Livewire\Devices\Overview;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\User;
use App\Queries\DeviceMetricQueries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forget(config('agent.latest_version_cache_key'));
    $this->user = User::factory()->create();
});

function linuxServer(array $attributes = []): Device
{
    return Device::factory()->monitorOnly()->active()->create([
        'hostname' => 'LXC-APP',
        'last_seen' => now(),
        'disks' => null,
        ...$attributes,
    ]);
}

function linuxReport(Device $device, array $attributes = [], array $disks = []): DeviceMetric
{
    $metric = DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'load1' => 0.5,
        'load5' => 0.4,
        'load15' => 0.3,
        'cpu_queue_length' => null,
        ...$attributes,
    ]);
    $metric->recordDisks($disks);

    return $metric;
}

describe('overview', function (): void {
    it('shows load, swap and disk busy for a linux server, never the windows CPU queue', function (): void {
        $device = linuxServer();
        linuxReport($device, ['swap_used_mib' => 256.0, 'swap_total_mib' => 1024.0, 'disk_busy_percent' => 12.5]);

        Livewire::actingAs($this->user)->test(Overview::class, ['device' => $device])
            ->assertSee('Load Average 0.50')
            ->assertSeeHtml('data-swap-usage')
            ->assertSee('Swap')
            ->assertSee('25.0%')
            ->assertSee('12.5% busy')
            ->assertDontSee('Page File')
            ->assertDontSee('CPU Queue');
    });

    it('says No swap when a linux server has none', function (): void {
        $device = linuxServer();
        linuxReport($device, ['swap_used_mib' => null, 'swap_total_mib' => null, 'disk_busy_percent' => 4.0]);

        Livewire::actingAs($this->user)->test(Overview::class, ['device' => $device])
            ->assertSee('No swap');
    });

    it('lists failed services, a pending reboot and waiting updates in the health card', function (): void {
        $device = linuxServer();
        linuxReport($device, [
            'failed_units' => ['backup.service'],
            'reboot_required' => true,
            'pending_updates' => 14,
            'pending_security_updates' => 3,
            'updates_checked_at' => now()->subMinutes(20),
        ]);

        Livewire::actingAs($this->user)->test(Overview::class, ['device' => $device])
            ->assertSeeHtml('data-linux-health')
            ->assertSee('backup.service')
            ->assertDontSee('All services running')
            ->assertSee('Reboot required')
            ->assertSee('14 updates waiting')
            ->assertSee('3 security')
            ->assertSee("As of the box's last apt update", false)
            ->assertSee('checked 20 minutes ago');
    });

    it('says all services are running only when the agent checked', function (?array $failedUnits, string $shown, string $hidden): void {
        $device = linuxServer();
        linuxReport($device, ['failed_units' => $failedUnits, 'reboot_required' => false, 'pending_updates' => 0]);

        Livewire::actingAs($this->user)->test(Overview::class, ['device' => $device])
            ->assertSee($shown)
            ->assertDontSee($hidden)
            ->assertDontSee('Reboot required')
            ->assertSee('No updates waiting');
    })->with([
        'checked, none failed' => [[], 'All services running', 'systemctl is not available'],
        'systemctl missing' => [null, 'systemctl is not available', 'All services running'],
    ]);

    it('hides the health card for windows devices and old linux agents', function (string $state): void {
        $device = Device::factory()->active()->{$state}()->create(['last_seen' => now()]);
        DeviceMetric::factory()->create(['device_id' => $device->id]);

        Livewire::actingAs($this->user)->test(Overview::class, ['device' => $device])
            ->assertDontSeeHtml('data-linux-health');
    })->with(['windows', 'linux']);

    it('shows inode usage under each disk, coloured at the configured thresholds', function (float $inodes, string $color): void {
        $device = linuxServer();
        linuxReport($device, disks: [['mount_point' => '/', 'total_gb' => 100.0, 'available_gb' => 60.0, 'inode_usage_percent' => $inodes]]);

        Livewire::actingAs($this->user)->test(Overview::class, ['device' => $device])
            ->assertSee(number_format($inodes, 1).'% of inodes used')
            ->assertSeeHtml($color);
    })->with([
        'plenty left' => [40.0, 'text-zinc-500'],
        'at warning' => [80.0, 'text-amber-600'],
        'at critical' => [95.0, 'text-red-600'],
    ]);

    it('leaves the inode line out when the filesystem reports none', function (): void {
        $device = linuxServer();
        linuxReport($device, disks: [['mount_point' => '/', 'total_gb' => 100.0, 'available_gb' => 60.0]]);

        Livewire::actingAs($this->user)->test(Overview::class, ['device' => $device])
            ->assertSee('Disk Storage')
            ->assertDontSeeHtml('data-inode-usage');
    });
});

describe('metrics tab', function (): void {
    beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC')));

    it('charts disk read and write summed across disks for linux servers', function (): void {
        $device = linuxServer();
        DeviceMetric::factory()->create(['device_id' => $device->id, 'recorded_at' => now()->subMinutes(8)])
            ->recordDisks([
                ['mount_point' => '/', 'read_kbps' => 100.0, 'write_kbps' => 40.0],
                ['mount_point' => '/srv', 'read_kbps' => 20.0, 'write_kbps' => 10.0],
            ]);

        expect(app(DeviceMetricQueries::class)->diskThroughput($device, MetricRange::OneDay)->sole())->toBe([
            'time' => '2026-10-03T11:50:00Z',
            'read' => 120.0,
            'write' => 50.0,
        ]);
    });

    it('explains a missing disk I/O chart instead of drawing zeros', function (): void {
        $device = linuxServer();
        collect([31, 11])->each(fn (int $minutes) => DeviceMetric::factory()->create(['device_id' => $device->id, 'recorded_at' => now()->subMinutes($minutes)])
            ->recordDisks([['mount_point' => '/', 'total_gb' => 100.0, 'available_gb' => 50.0]]));

        $chart = MetricChart::diskThroughput(app(DeviceMetricQueries::class)->diskThroughput($device, MetricRange::OneDay));

        expect($chart->isDrawable())->toBeFalse();

        Livewire::actingAs($this->user)->test(Metrics::class, ['device' => $device])
            ->assertSee('Disk read & write')
            ->assertSee('Containers on ZFS never expose them', false);
    });

    it('names the swap series per platform', function (): void {
        $device = linuxServer();
        collect([31, 11])->each(fn (int $minutes) => DeviceMetric::factory()->create(['device_id' => $device->id, 'recorded_at' => now()->subMinutes($minutes), 'disk_busy_percent' => 5.0, 'swap_used_mib' => 10.0, 'swap_total_mib' => 100.0]));

        Livewire::actingAs($this->user)->test(Metrics::class, ['device' => $device])
            ->assertSee('Disk busy & Swap')
            ->assertDontSee('Page File');
    });
});

it('lists linux top processes on the apps tab', function (): void {
    $device = linuxServer();
    linuxReport($device)->recordApps([['name' => 'postgres', 'cpu_percent' => 22.5, 'memory_mib' => 812.0]]);

    Livewire::actingAs($this->user)->test(Apps::class, ['device' => $device])
        ->assertSee('Top Apps')
        ->assertSee('postgres')
        ->assertSee('22.5%');
});

it('shows linux adapter speed and error and drop counters since boot on the details tab', function (): void {
    $device = linuxServer();
    linuxReport($device)->recordNetworkInterfaces([[
        'interface' => 'eth0',
        'received_kbps' => 850.0,
        'sent_kbps' => 120.0,
        'errors_inbound' => 3,
        'errors_outbound' => 0,
        'drops_inbound' => 12,
        'drops_outbound' => 1,
        'link_speed_kbps' => 10000000,
    ]]);

    Livewire::actingAs($this->user)->test(Details::class, ['device' => $device])
        ->assertSee('eth0')
        ->assertSee('10 Gbps')
        ->assertSee('Errors since boot')
        ->assertSee('Drops since boot')
        ->assertSee('3 / 0')
        ->assertSee('12 / 1')
        ->assertDontSee('Errors/s');
});

it('keeps per-second wording for windows adapters', function (): void {
    $device = Device::factory()->active()->windows()->create();
    DeviceMetric::factory()->create(['device_id' => $device->id])
        ->recordNetworkInterfaces([['interface' => 'Ethernet', 'errors_inbound' => 0.5, 'errors_outbound' => 0.0]]);

    Livewire::actingAs($this->user)->test(Details::class, ['device' => $device])
        ->assertSee('Errors/s')
        ->assertDontSee('since boot');
});

describe('dashboard', function (): void {
    it('flags failed services as critical', function (): void {
        $device = linuxServer(['hostname' => 'LXC-BROKEN']);
        linuxReport($device, ['failed_units' => ['backup.service']]);

        Livewire::actingAs($this->user)->test(Dashboard::class)
            ->assertDontSee('All clear')
            ->assertSee('LXC-BROKEN')
            ->assertSee('backup.service')
            ->assertSeeHtml('data-attention-severity="critical"');
    });

    it('flags inodes at or over the warning threshold, critical at the critical threshold', function (float $inodes, ?string $severity): void {
        $device = linuxServer(['hostname' => 'LXC-INODES']);
        linuxReport($device, disks: [['mount_point' => '/var', 'total_gb' => 100.0, 'available_gb' => 90.0, 'inode_usage_percent' => $inodes]]);

        $dashboard = Livewire::actingAs($this->user)->test(Dashboard::class);

        $severity === null
            ? $dashboard->assertSee('All clear')
            : $dashboard->assertSeeHtml('data-attention-severity="'.$severity.'"')->assertSeeHtml('data-attention-inodes')->assertSee(number_format($inodes, 1).'% of inodes used');
    })->with([
        'under warning' => [79.0, null],
        'at warning' => [80.0, 'warning'],
        'at critical' => [90.0, 'critical'],
    ]);

    it('lists a pending reboot as worth knowing without breaking the all clear', function (): void {
        $device = linuxServer(['hostname' => 'LXC-REBOOT']);
        linuxReport($device, ['reboot_required' => true, 'pending_updates' => 40, 'pending_security_updates' => 9]);

        Livewire::actingAs($this->user)->test(Dashboard::class)
            ->assertSee('All clear')
            ->assertSeeHtml('data-worth-knowing')
            ->assertSee('LXC-REBOOT')
            ->assertSee('Reboot required')
            ->assertSeeHtml('data-attention-severity="info"')
            ->assertViewHas('needsAttention', fn ($attention): bool => $attention->isEmpty());
    });

    it('never puts pending updates on the attention list', function (): void {
        linuxReport(linuxServer(), ['pending_updates' => 120, 'pending_security_updates' => 30, 'reboot_required' => false, 'failed_units' => []]);

        Livewire::actingAs($this->user)->test(Dashboard::class)
            ->assertSee('All clear')
            ->assertDontSeeHtml('data-worth-knowing');
    });

    it('orders failed services and full inodes with the other critical problems, reboots last', function (): void {
        linuxReport(linuxServer(['hostname' => 'REBOOT-ONLY']), ['reboot_required' => true]);
        linuxReport(linuxServer(['hostname' => 'DISK-WARNING', 'disks' => [['name' => '/', 'total_gb' => 100.0, 'available_gb' => 20.0]]]));
        linuxReport(linuxServer(['hostname' => 'FAILED-UNIT']), ['failed_units' => ['nginx.service']]);
        linuxReport(linuxServer(['hostname' => 'DISK-CRITICAL', 'disks' => [['name' => '/', 'total_gb' => 100.0, 'available_gb' => 3.0]]]));

        Livewire::actingAs($this->user)->test(Dashboard::class)
            ->assertViewHas('needsAttention', fn ($attention): bool => $attention->map(fn (DeviceAttention $item): string => $item->device->hostname)->all() === ['DISK-CRITICAL', 'FAILED-UNIT', 'DISK-WARNING'])
            ->assertViewHas('worthKnowing', fn ($attention): bool => $attention->map(fn (DeviceAttention $item): string => $item->device->hostname)->all() === ['REBOOT-ONLY']);
    });
});
