<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Enums\ScriptCategory;
use App\Events\MetricsReceived;
use App\Events\NetdataWentQuiet;
use App\Listeners\RepairQuietNetdata;
use App\Listeners\WatchForSilentNetdata;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceMetric;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

pest()->use(RefreshDatabase::class);

function reportMetrics(Device $device, ?float $cpu): void
{
    MetricsReceived::dispatch($device, DeviceMetric::factory()->create(['device_id' => $device->id, 'cpu' => $cpu]));
}

describe('watching for blank reports', function (): void {
    beforeEach(function (): void {
        Event::fake([NetdataWentQuiet::class]);
        $this->device = Device::factory()->windows()->active()->create();
    });

    it('listens for metrics', function (): void {
        Event::assertListening(MetricsReceived::class, WatchForSilentNetdata::class);
        Event::assertListening(NetdataWentQuiet::class, RepairQuietNetdata::class);
    });

    it('does not fire before the threshold', function (): void {
        reportMetrics($this->device, null);
        reportMetrics($this->device, null);

        Event::assertNotDispatched(NetdataWentQuiet::class);
    });

    it('fires on every blank report from the threshold on, so a failed repair is tried again', function (): void {
        collect(range(1, 5))->each(fn (): null => reportMetrics($this->device, null));

        Event::assertDispatchedTimes(NetdataWentQuiet::class, 3);
        Event::assertDispatched(NetdataWentQuiet::class, fn (NetdataWentQuiet $event): bool => $event->device->is($this->device));
    });

    it('counts on the database cache production uses, where increment alone does nothing on a new key', function (): void {
        config(['cache.default' => 'database']);

        collect(range(1, 3))->each(fn (): null => reportMetrics($this->device, null));

        Event::assertDispatchedTimes(NetdataWentQuiet::class, 1);
    });

    it('starts counting again after a report with CPU', function (): void {
        reportMetrics($this->device, null);
        reportMetrics($this->device, null);
        reportMetrics($this->device, 12.5);
        reportMetrics($this->device, null);
        reportMetrics($this->device, null);

        Event::assertNotDispatched(NetdataWentQuiet::class);

        reportMetrics($this->device, null);

        Event::assertDispatchedTimes(NetdataWentQuiet::class, 1);
    });

    it('counts each device on its own', function (): void {
        $other = Device::factory()->windows()->active()->create();

        reportMetrics($this->device, null);
        reportMetrics($other, null);
        reportMetrics($this->device, null);
        reportMetrics($other, null);

        Event::assertNotDispatched(NetdataWentQuiet::class);
    });

    it('follows the configured threshold', function (): void {
        config(['devices.metrics.blank_reports_before_repair' => 1]);

        reportMetrics($this->device, null);

        Event::assertDispatchedTimes(NetdataWentQuiet::class, 1);
    });

    it('ignores monitor-only devices', function (): void {
        $device = Device::factory()->monitorOnly()->active()->create();

        collect(range(1, 5))->each(fn (): null => reportMetrics($device, null));

        Event::assertNotDispatched(NetdataWentQuiet::class);
    });
});

describe('repairing quiet Netdata', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        $this->script = Script::factory()->system()->create(['slug' => 'repair-netdata']);
        $this->device = Device::factory()->windows()->active()->create();
    });

    it('queues the repair script after three blank reports in a row', function (): void {
        collect(range(1, 3))->each(fn (): null => reportMetrics($this->device, null));

        $command = DeviceCommand::query()->where('device_id', $this->device->id)->sole();
        expect($command->script_id)->toBe($this->script->id)
            ->and($command->status)->toBe(CommandStatus::Pending)
            ->and($command->queuedBy->name)->toBe('Claudette');
    });

    it('queues one repair a day however many blank reports arrive', function (): void {
        collect(range(1, 10))->each(fn (): null => reportMetrics($this->device, null));

        expect(DeviceCommand::query()->where('script_id', $this->script->id)->count())->toBe(1);

        $this->travel(25)->hours();
        reportMetrics($this->device, null);

        expect(DeviceCommand::query()->where('script_id', $this->script->id)->count())->toBe(2);
    });

    it('queues it as the Claudette automation user, created once', function (): void {
        NetdataWentQuiet::dispatch($this->device);
        NetdataWentQuiet::dispatch(Device::factory()->windows()->active()->create());

        expect(User::query()->where('email', config('devices.automation_user.email'))->count())->toBe(1)
            ->and(DeviceCommand::query()->pluck('queued_by')->unique()->all())->toBe([User::automation()->id]);
    });

    it('leaves the automation user out of humans, so she gets no notifications', function (): void {
        $claudette = User::automation();

        expect(User::query()->humans()->pluck('id')->all())->toBe([$this->user->id])
            ->and(Hash::check('', $claudette->password))->toBeFalse();
    });

    it('does not queue it again within the cooldown', function (): void {
        DeviceCommand::factory()->failed()->create([
            'device_id' => $this->device->id,
            'script_id' => $this->script->id,
            'queued_at' => now()->subHours(23),
        ]);

        NetdataWentQuiet::dispatch($this->device);

        expect(DeviceCommand::query()->where('script_id', $this->script->id)->count())->toBe(1);
    });

    it('queues it again once the cooldown has passed', function (): void {
        DeviceCommand::factory()->failed()->create([
            'device_id' => $this->device->id,
            'script_id' => $this->script->id,
            'queued_at' => now()->subHours(25),
        ]);

        NetdataWentQuiet::dispatch($this->device);

        expect(DeviceCommand::query()->where('script_id', $this->script->id)->count())->toBe(2);
    });

    it('does nothing when the system script is missing', function (): void {
        $this->script->delete();

        NetdataWentQuiet::dispatch($this->device);

        expect(DeviceCommand::query()->exists())->toBeFalse();
    });

    it('ignores a user-made script that shares the slug', function (): void {
        $this->script->delete();
        Script::factory()->create(['slug' => 'repair-netdata']);

        NetdataWentQuiet::dispatch($this->device);

        expect(DeviceCommand::query()->exists())->toBeFalse();
    });

    it('does not queue it on a Linux device', function (): void {
        $device = Device::factory()->linux()->active()->create();

        NetdataWentQuiet::dispatch($device);

        expect(DeviceCommand::query()->exists())->toBeFalse();
    });
});

it('syncs repair-netdata as an admin maintenance script for Windows', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('repair-netdata');

    expect($script->category)->toBe(ScriptCategory::Maintenance)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->platform->value)->toBe('windows')
        ->and(config('devices.metrics.netdata_repair_script_slug'))->toBe('repair-netdata');
});

it('restarts Netdata, rebuilds 64 and 32 bit performance counters and reports the outcome', function (): void {
    $script = file_get_contents(resource_path('scripts/windows/repair-netdata.ps1'));

    expect($script)
        ->toContain('/api/v3/contexts')
        ->toContain("'system.cpu'")
        ->toContain('Perflib\009')
        ->toContain("-notcontains 'Processor'")
        ->toContain('"$env:SystemRoot\System32", "$env:SystemRoot\SysWOW64"')
        ->toContain('lodctr.exe" /R')
        ->toContain('winmgmt /resyncperf')
        ->toContain('ATTENTION: Netdata is not installed; run Install Netdata')
        ->toContain('Nothing to do: Netdata is reporting CPU')
        ->toContain('OK: Netdata is reporting CPU again')
        ->not->toContain('try {')
        ->not->toMatch('/[^\x00-\x7F]/');

    expect(strpos($script, 'winmgmt /resyncperf'))->toBeGreaterThan(strpos($script, 'lodctr.exe" /R'));
});

it('asks Netdata to stop, then kills every process running from its folder, and never reboots', function (): void {
    $script = file_get_contents(resource_path('scripts/windows/repair-netdata.ps1'));

    expect($script)
        ->toContain("Stop-Service -Name 'netdata' -Force -NoWait")
        ->toContain('$netdataFolder = "$env:ProgramFiles\Netdata"')
        ->toContain('Where-Object { $_.ExecutablePath -like "$netdataFolder\*" }')
        ->toContain('Stop-Process -Id $_.ProcessId -Force')
        ->toContain('killing $($processes.Count) Netdata processes: $names')
        ->toContain("Start-Service -Name 'netdata'")
        ->toContain('ATTENTION: Netdata is still not reporting CPU after a forced restart. Rebooting the PC is the next step')
        ->not->toContain('Restart-Service')
        ->not->toContain('Restart-Computer')
        ->not->toContain('shutdown')
        ->not->toMatch('/Stop-Process -Name/');

    expect(strpos($script, 'Stop-Process -Id'))->toBeGreaterThan(strpos($script, 'Stop-Service -Name'));
});
