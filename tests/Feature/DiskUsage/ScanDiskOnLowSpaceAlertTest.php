<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\AlertMetric;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceMetric;
use App\Models\Script;
use App\Models\User;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->device = Device::factory()->active()->windows()->create(['agent_version' => '0.9.0']);
});

/**
 * @param  array<string, float>  $volumes  Mount point => percent used
 */
function reportVolumes(Device $device, array $volumes): void
{
    $metric = DeviceMetric::factory()->create(['device_id' => $device->id, 'recorded_at' => now()]);

    collect($volumes)->each(fn (float $percent, string $mountPoint) => $metric->diskMetrics()->create(['mount_point' => $mountPoint, 'usage_percent' => $percent]));
}

function openAlert(Device $device, AlertMetric $metric = AlertMetric::Disk): Alert
{
    return Alert::factory()->create(['device_id' => $device->id, 'metric' => $metric]);
}

/**
 * @return Illuminate\Support\Collection<int, DeviceCommand>
 */
function diskScanCommands(Device $device): Illuminate\Support\Collection
{
    return $device->commands()->where('script_id', Script::findSystem('disk-usage')->id)->get();
}

it('scans the fullest drive as the automation user when a disk alert opens', function (): void {
    reportVolumes($this->device, ['C:' => 70.0, 'D:' => 96.5]);

    openAlert($this->device);

    expect(diskScanCommands($this->device)->sole())
        ->parameters->toBe(['Path' => 'D:\\', 'Depth' => '4'])
        ->queued_by->toBe(User::automation()->id);
});

it('scans a drive at most once within the cooldown, but other drives still get theirs', function (): void {
    reportVolumes($this->device, ['C:' => 95.0]);
    openAlert($this->device)->resolve();
    $this->travel(2)->hours();
    DeviceCommand::query()->update(['status' => 'completed']);

    openAlert($this->device);

    reportVolumes($this->device, ['C:' => 50.0, 'E:' => 99.0]);
    openAlert($this->device);

    expect(diskScanCommands($this->device)->pluck('parameters.Path')->all())->toBe(['C:\\', 'E:\\']);

    $this->travel(config('disk_usage.auto_scan_cooldown_hours'))->hours();
    DeviceCommand::query()->update(['status' => 'completed']);
    reportVolumes($this->device, ['C:' => 96.0]);
    openAlert($this->device);

    expect(diskScanCommands($this->device))->toHaveCount(3);
});

it('only scans for newly opened disk alerts', function (): void {
    reportVolumes($this->device, ['C:' => 95.0]);

    openAlert($this->device, AlertMetric::Cpu);
    Alert::factory()->acknowledged()->create(['device_id' => $this->device->id, 'metric' => AlertMetric::Disk])->update(['current_value' => 97]);

    expect(diskScanCommands($this->device))->toBeEmpty();
});

it('never scans linux, monitor-only or outdated agents, or volumes without a drive letter', function (Closure $device, array $volumes): void {
    $device = $device();
    reportVolumes($device, $volumes);

    openAlert($device);

    expect(diskScanCommands($device))->toBeEmpty();
})->with([
    'linux' => [fn (): Device => Device::factory()->active()->linux()->create(['agent_version' => '0.9.0']), ['/' => 95.0]],
    'monitor only' => [fn (): Device => Device::factory()->active()->monitorOnly()->create(['agent_version' => '0.9.0', 'os' => 'Windows 11 Pro']), ['C:' => 95.0]],
    'agent without parameters' => [fn (): Device => Device::factory()->active()->windows()->create(['agent_version' => '0.5.0']), ['C:' => 95.0]],
    'no drive letter' => [fn (): Device => Device::factory()->active()->windows()->create(['agent_version' => '0.9.0']), ['HarddiskVolume3' => 95.0]],
    'no volumes reported' => [fn (): Device => Device::factory()->active()->windows()->create(['agent_version' => '0.9.0']), []],
]);
