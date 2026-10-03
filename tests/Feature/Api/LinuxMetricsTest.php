<?php

declare(strict_types=1);

use App\Enums\AlertMetric;
use App\Enums\AlertOperator;
use App\Enums\AlertStatus;
use App\Livewire\AlertBell;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

/**
 * @param  array<int, array<string, mixed>>  $disks
 */
function postLinuxDisks(array $disks): Illuminate\Testing\TestResponse
{
    return test()->withHeaders(['X-Device-Key' => 'LINUX-KEY'])->postJson('/api/metrics', [
        'monitor_only' => true,
        'agent_version' => '0.7.0',
        'cpu' => ['usage_percent' => 5],
        'memory' => ['usage_percent' => 30],
        'system_info' => ['os_name' => 'Debian GNU/Linux', 'kernel_name' => 'Linux'],
        'disks' => $disks,
    ]);
}

function linuxVolume(string $mountPoint, string $filesystem, float $usagePercent): array
{
    return [
        'mount_point' => $mountPoint,
        'filesystem' => $filesystem,
        'total_gb' => 100.0,
        'used_gb' => $usagePercent,
        'available_gb' => 100.0 - $usagePercent,
        'usage_percent' => $usagePercent,
    ];
}

it('stores real Linux volumes and drops container, pseudo and RAM-backed mounts', function (): void {
    $device = Device::factory()->withApiKey('LINUX-KEY')->create();

    postLinuxDisks([
        linuxVolume('/', 'ext4', 40),
        linuxVolume('/srv/data', 'zfs', 55),
        linuxVolume('/tmp', 'xfs', 10),
        linuxVolume('/var/lib/docker/overlay2/abc123/merged', 'overlay', 99),
        linuxVolume('/var/lib/docker', 'ext4', 99),
        linuxVolume('/var/lib/containers/storage/overlay', 'xfs', 99),
        linuxVolume('/var/lib/lxcfs', 'fuse.lxcfs', 100),
        linuxVolume('/proc/sys/fs/binfmt_misc', 'binfmt_misc', 100),
        linuxVolume('/dev/shm', 'tmpfs', 100),
        linuxVolume('/run/user/1000', 'tmpfs', 100),
        linuxVolume('/mnt/ramdisk', 'tmpfs', 100),
        linuxVolume('/media/cdrom', 'squashfs', 100),
        linuxVolume('/boot/efi', 'vfat', 90),
    ])->assertSuccessful();

    expect(DeviceMetric::query()->sole()->diskMetrics->pluck('mount_point')->all())
        ->toBe(['/', '/srv/data', '/tmp']);
});

it('raises a disk alert in the bell when a Linux volume crosses the threshold', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->withApiKey('LINUX-KEY')->create(['hostname' => 'pve-backup']);
    AlertRule::factory()->create([
        'metric' => AlertMetric::Disk,
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 90,
        'duration_minutes' => 1,
    ]);

    $this->travel(-2)->minutes();
    postLinuxDisks([linuxVolume('/', 'ext4', 40), linuxVolume('/srv/backups', 'ext4', 95)])->assertSuccessful();
    $this->travelBack();

    postLinuxDisks([linuxVolume('/', 'ext4', 40), linuxVolume('/srv/backups', 'ext4', 96.5), linuxVolume('/dev/shm', 'tmpfs', 100)])->assertSuccessful();

    $alert = Alert::query()->where('device_id', $device->id)->sole();
    expect($alert->status)->toBe(AlertStatus::Triggered)
        ->and($alert->metric)->toBe(AlertMetric::Disk)
        ->and($alert->current_value)->toBe(96.5);

    Livewire::withoutLazyLoading()->actingAs($user)->test(AlertBell::class)
        ->assertViewHas('unreadCount', 1)
        ->assertSee('pve-backup');
});

it('ignores a full tmpfs when evaluating the disk alert', function (): void {
    Device::factory()->withApiKey('LINUX-KEY')->create();
    AlertRule::factory()->create([
        'metric' => AlertMetric::Disk,
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 90,
        'duration_minutes' => 0,
    ]);

    postLinuxDisks([linuxVolume('/', 'ext4', 40), linuxVolume('/run/lock', 'tmpfs', 100), linuxVolume('/mnt/scratch', 'tmpfs', 100)])->assertSuccessful();

    expect(Alert::query()->exists())->toBeFalse();
});
