<?php

declare(strict_types=1);

use App\Events\DiskScanStored;
use App\Livewire\Devices\Storage;
use App\Models\Device;
use App\Models\DeviceDiskScan;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->linux()->monitorOnly()->withApiKey('LINUX-KEY')->create(['hostname' => 'mariadb']);
});

function linuxDiskScan(array $overrides = []): array
{
    $data = json_decode(file_get_contents(base_path('tests/Fixtures/disk-usage-linux-root.json')), true);

    collect($overrides)->each(function (mixed $value, string $key) use (&$data): void {
        data_set($data, $key, $value);
    });

    return $data;
}

function postDiskScanMetrics(array $extra): TestResponse
{
    return test()->withHeaders(['X-Device-Key' => 'LINUX-KEY'])->postJson('/api/metrics', [
        'monitor_only' => true,
        'agent_version' => '0.10.0',
        'cpu' => ['usage_percent' => 12],
        ...$extra,
    ]);
}

it('stores the disk scan a monitor-only Linux agent sends with its metrics', function (): void {
    Event::fake([DiskScanStored::class]);

    postDiskScanMetrics(['disk_scan' => linuxDiskScan()])->assertSuccessful();

    $scan = DeviceDiskScan::query()->sole();
    expect($scan->device_id)->toBe($this->device->id)
        ->and($scan->root)->toBe('/')
        ->and($scan->depth)->toBe(config('disk_usage.default_depth'))
        ->and($scan->files)->toBe(184213)
        ->and($scan->error_count)->toBe(1);
    Event::assertDispatched(DiskScanStored::class);
});

it('keeps the metrics but stores no scan when the report is unreadable', function (): void {
    postDiskScanMetrics(['disk_scan' => linuxDiskScan(['schema' => 'rmm.du/9'])])->assertSuccessful();

    expect(DeviceDiskScan::query()->count())->toBe(0)
        ->and($this->device->metrics()->count())->toBe(1);
});

it('stores nothing when metrics come without a scan', function (): void {
    postDiskScanMetrics([])->assertSuccessful();

    expect(DeviceDiskScan::query()->count())->toBe(0);
});

it('rejects a disk scan that is not an object', function (): void {
    postDiskScanMetrics(['disk_scan' => 'big'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['disk_scan' => 'The disk scan must be a disk-usage report object.']);
});

it('shows a Linux scan with forward-slash paths and nothing to run or delete', function (): void {
    postDiskScanMetrics(['disk_scan' => linuxDiskScan()])->assertSuccessful();

    Livewire::actingAs($this->user)->withQueryParams(['node' => '1.2'])->test(Storage::class, ['device' => $this->device])
        ->assertSeeInOrder(['mysql', 'apt'])
        ->assertSee('/var/lib/mysql/ibdata1')
        ->assertDontSee('\\var')
        ->assertDontSeeHtml('data-storage-unscannable')
        ->assertDontSeeHtml('data-scan-now')
        ->assertDontSeeHtml('data-scan-folder');
});

it('shows the volume bar and unaccounted space for a scan of a mount point', function (): void {
    postDiskScanMetrics(['disk_scan' => linuxDiskScan(['totals.unaccounted' => 7516192768])])->assertSuccessful();

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertSeeHtml('data-volume-bar')
        ->assertSee('7.0 GB free of 126.0 GB')
        ->assertSeeInOrder(['Unaccounted', '7.0 GB']);
});

it('borrows the volume figures from the disk metrics of the mount point when the scan has none', function (): void {
    postDiskScanMetrics(['disk_scan' => linuxDiskScan(['volume' => null])])->assertSuccessful();
    DeviceMetric::factory()->create(['device_id' => $this->device->id, 'recorded_at' => now()])
        ->recordDisks([['mount_point' => '/', 'used_gb' => 116.0, 'available_gb' => 4.0, 'total_gb' => 120.0]]);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertSeeHtml('data-volume-bar')
        ->assertSee('4.0 GB free of 120.0 GB')
        ->assertSeeInOrder(['Unaccounted', '4.0 GB']);
});

it('shows no volume bar for a Linux scan that is not of a mount point', function (): void {
    postDiskScanMetrics(['disk_scan' => linuxDiskScan(['root' => '/var/lib', 'nodes.0.1' => '/var/lib'])])->assertSuccessful();

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertDontSeeHtml('data-volume-bar');
});
