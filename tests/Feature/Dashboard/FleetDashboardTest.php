<?php

declare(strict_types=1);

use App\DTOs\DeviceAttention;
use App\Enums\AlertSeverity;
use App\Enums\DevicePowerState;
use App\Livewire\Dashboard;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forever(config('agent.latest_version_cache_key'), '0.7.0');
    $this->user = User::factory()->create();
});

/**
 * An online device with nothing wrong, unless the attributes say otherwise.
 */
function healthyDevice(array $attributes = []): Device
{
    return Device::factory()->active()->create([
        'last_seen' => now(),
        'agent_version' => '0.7.0',
        'disks' => [['name' => 'C:', 'total_gb' => 100.0, 'available_gb' => 60.0]],
        ...$attributes,
    ]);
}

function diskAt(float $usedPercent, string $name = '/'): array
{
    return [['name' => $name, 'total_gb' => 100.0, 'available_gb' => 100.0 - $usedPercent]];
}

it('replaces the starter kit placeholder with the fleet dashboard', function (): void {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSeeLivewire(Dashboard::class)
        ->assertSee('Needs attention')
        ->assertSee('Disk space across the fleet')
        ->assertDontSeeHtml('placeholder-pattern');
});

it('sends guests to log in and refuses users who may not see devices', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'viewAny' ? false : null);

    $this->actingAs($this->user)->get(route('dashboard'))->assertForbidden();
});

it('marks Dashboard current in the sidebar', function (): void {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertSeeHtml('href="'.route('dashboard').'" data-current');
});

it('shows all clear when nothing needs attention', function (): void {
    healthyDevice(['hostname' => 'CALM-01']);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertSeeHtml('data-all-clear')
        ->assertSee('All clear')
        ->assertViewHas('needsAttention', fn ($attention): bool => $attention->isEmpty());
});

it('flags disks at or over the warning threshold and colours them critical at the critical threshold', function (float $usedPercent, ?string $severity, string $barColor): void {
    healthyDevice(['hostname' => 'DISKY', 'disks' => diskAt($usedPercent, '/var')]);

    $component = Livewire::actingAs($this->user)->test(Dashboard::class);

    if ($severity === null) {
        $component->assertSee('All clear');

        return;
    }

    $component->assertDontSee('All clear')
        ->assertSeeHtml('data-attention-severity="'.$severity.'"')
        ->assertSeeInOrder(['DISKY', '/var', number_format($usedPercent, 1).'%'])
        ->assertSeeHtml($barColor);
})->with([
    'under warning' => [74.0, null, 'var(--color-blue-600)'],
    'at warning' => [75.0, 'warning', 'var(--color-amber-600)'],
    'over warning' => [82.0, 'warning', 'var(--color-amber-600)'],
    'at critical' => [90.0, 'critical', 'var(--color-red-600)'],
    'over critical' => [92.0, 'critical', 'var(--color-red-600)'],
]);

it('reads disk thresholds from config', function (): void {
    config(['devices.disk.warning_percent' => 50, 'devices.disk.critical_percent' => 60]);
    healthyDevice(['hostname' => 'HALF-FULL', 'disks' => diskAt(55.0)]);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertSee('HALF-FULL')
        ->assertSeeHtml('data-attention-severity="warning"');
});

it('takes reported volumes over the enrolment disk list', function (): void {
    $device = healthyDevice(['hostname' => 'LXC-DB', 'disks' => diskAt(10.0)]);
    DeviceMetric::factory()->create(['device_id' => $device->id])
        ->recordDisks([['mount_point' => '/srv', 'total_gb' => 100.0, 'available_gb' => 7.0]]);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertSeeInOrder(['LXC-DB', '/srv', '93.0%']);
});

it('lists open alerts, devices that went quiet and old agents', function (): void {
    $alerting = healthyDevice(['hostname' => 'ALERTING']);
    Alert::factory()->create(['device_id' => $alerting->id, 'severity' => AlertSeverity::Warning, 'triggered_at' => now()->subMinutes(5)]);
    Alert::factory()->resolved()->create(['device_id' => healthyDevice(['hostname' => 'WAS-ALERTING'])->id]);
    healthyDevice(['hostname' => 'QUIET-PC', 'last_seen' => now()->subHour()]);
    healthyDevice(['hostname' => 'ASLEEP-PC', 'power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()]);
    healthyDevice(['hostname' => 'OLD-AGENT', 'agent_version' => '0.6.5']);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertViewHas('needsAttention', fn ($attention): bool => $attention->map(fn (DeviceAttention $item): string => $item->device->hostname)->sort()->values()->all() === ['ALERTING', 'OLD-AGENT', 'QUIET-PC'])
        ->assertSee('since 5 minutes ago')
        ->assertSee('Went quiet without announcing a shutdown')
        ->assertSee('Update available 0.6.5');
});

it('puts the worst devices first', function (): void {
    healthyDevice(['hostname' => 'OLD-AGENT', 'agent_version' => '0.6.5']);
    healthyDevice(['hostname' => 'FILLING', 'disks' => diskAt(80.0)]);
    healthyDevice(['hostname' => 'NEARLY-FULL', 'disks' => diskAt(92.0)]);
    healthyDevice(['hostname' => 'FULL', 'disks' => diskAt(97.0)]);
    Alert::factory()->create(['device_id' => healthyDevice(['hostname' => 'CRITICAL-ALERT'])->id, 'severity' => AlertSeverity::Critical]);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertViewHas('needsAttention', fn ($attention): bool => $attention->map(fn (DeviceAttention $item): string => $item->device->hostname)->all() === ['FULL', 'NEARLY-FULL', 'CRITICAL-ALERT', 'FILLING', 'OLD-AGENT']);
});

it('links every row to its device', function (): void {
    $device = healthyDevice(['hostname' => 'LINKED', 'disks' => diskAt(95.0)]);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertSeeHtml('href="'.route('devices.show', $device).'"');
});

it('marks monitor-only servers in the attention list', function (): void {
    healthyDevice(['hostname' => 'LXC-FULL', 'disks' => diskAt(95.0), 'is_monitor_only' => true]);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertSeeHtml('data-monitor-only')
        ->assertSee('Monitor only');
});

it('sums up the fleet with managed and monitor-only counts and links through to the filtered list', function (): void {
    healthyDevice();
    healthyDevice(['is_monitor_only' => true]);
    healthyDevice(['is_monitor_only' => true, 'last_seen' => now()->subHour()]);
    healthyDevice(['power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()]);
    Device::factory()->create();

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertViewHas('summary', fn (array $summary): bool => $summary['total'] === 5
            && $summary['online'] === 2
            && $summary['poweringOff'] === 1
            && $summary['offline'] === 1
            && $summary['managed'] === 2
            && $summary['monitorOnly'] === 2)
        ->assertSeeHtml('href="'.route('devices.index', ['statusFilter' => 'online']).'"')
        ->assertSeeHtml('href="'.route('devices.index', ['statusFilter' => 'powering-off']).'"')
        ->assertSeeHtml('href="'.route('devices.index', ['statusFilter' => 'offline']).'"');
});

it('lists every device\'s fullest disk, fullest first, up to the configured number', function (): void {
    config(['dashboard.disk_rows' => 3]);
    healthyDevice(['hostname' => 'D-40', 'disks' => [...diskAt(40.0, 'C:'), ...diskAt(10.0, 'D:')]]);
    healthyDevice(['hostname' => 'D-92', 'disks' => diskAt(92.0)]);
    healthyDevice(['hostname' => 'D-60', 'disks' => diskAt(60.0)]);
    healthyDevice(['hostname' => 'D-20', 'disks' => diskAt(20.0)]);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertViewHas('fullestDisks', fn ($rows): bool => $rows->map(fn (array $row): string => $row['device']->hostname.' '.$row['disk']['name'])->all() === ['D-92 /', 'D-60 /', 'D-40 C:'])
        ->assertSee('All 4 devices')
        ->assertSeeHtml('href="'.route('devices.index').'"');
});

it('shows the busiest online devices by CPU or RAM', function (): void {
    config(['dashboard.busiest_devices' => 2]);
    DeviceMetric::factory()->create(['device_id' => healthyDevice(['hostname' => 'CPU-BOUND'])->id, 'cpu' => 95.0, 'ram' => 20.0]);
    DeviceMetric::factory()->create(['device_id' => healthyDevice(['hostname' => 'RAM-BOUND'])->id, 'cpu' => 5.0, 'ram' => 80.0]);
    DeviceMetric::factory()->create(['device_id' => healthyDevice(['hostname' => 'IDLE'])->id, 'cpu' => 2.0, 'ram' => 10.0]);
    DeviceMetric::factory()->create(['device_id' => healthyDevice(['hostname' => 'OFF-BUT-BUSY', 'last_seen' => now()->subHour()])->id, 'cpu' => 99.0, 'ram' => 99.0]);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertViewHas('busiest', fn ($devices): bool => $devices->pluck('hostname')->all() === ['CPU-BOUND', 'RAM-BOUND']);
});

it('shows the latest alerts with their status and links to the alerts page', function (): void {
    config(['dashboard.recent_alerts' => 2]);
    $device = healthyDevice(['hostname' => 'NOISY']);
    Alert::factory()->resolved()->create(['device_id' => $device->id, 'triggered_at' => now()->subHours(3)]);
    Alert::factory()->resolved()->create(['device_id' => $device->id, 'triggered_at' => now()->subHour()]);
    Alert::factory()->acknowledged()->create(['device_id' => $device->id, 'triggered_at' => now()]);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertViewHas('recentAlerts', fn ($alerts): bool => $alerts->count() === 2 && $alerts->first()->triggered_at->isToday())
        ->assertSee('Acknowledged')
        ->assertSee('Resolved')
        ->assertSeeHtml('href="'.route('alerts.index').'"');
});

it('shows recent activity only to users the audit policy allows', function (): void {
    AuditLog::factory()->create(['properties' => ['label' => 'VISIBLE-ACTIVITY']]);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertSee('Recent activity')
        ->assertSee('VISIBLE-ACTIVITY')
        ->assertSeeHtml('href="'.route('audit.index').'"');

    Gate::before(fn (User $user, string $ability, array $arguments): ?bool => $ability === 'viewAny' && ($arguments[0] ?? null) === AuditLog::class ? false : null);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertDontSee('Recent activity')
        ->assertDontSee('VISIBLE-ACTIVITY')
        ->assertViewHas('recentActivity', fn ($activity): bool => $activity->isEmpty());
});

describe('live refresh', function (): void {
    it('redraws at once when an alert changes', function (): void {
        $device = healthyDevice(['hostname' => 'SOON-ALERTING']);

        $dashboard = Livewire::actingAs($this->user)->test(Dashboard::class)->assertSee('All clear');

        $alert = Alert::factory()->create(['device_id' => $device->id]);

        $dashboard->dispatch('echo-private:devices,AlertChanged', ['alertId' => $alert->id, 'deviceId' => $device->id, 'status' => 'triggered'])
            ->assertDontSee('All clear')
            ->assertSee('SOON-ALERTING');
    });

    it('redraws at once when a device enrols', function (): void {
        $dashboard = Livewire::actingAs($this->user)->test(Dashboard::class);

        $device = Device::factory()->create(['hostname' => 'BRAND-NEW']);

        $dashboard->dispatch('echo-private:devices,DeviceEnrolled', ['deviceId' => $device->id])
            ->assertViewHas('summary', fn (array $summary): bool => $summary['total'] === 1);
    });

    it('lets heartbeats redraw a stale dashboard but not a fresh one', function (): void {
        $device = healthyDevice(['hostname' => 'FILLING-UP']);

        $dashboard = Livewire::actingAs($this->user)->test(Dashboard::class)->assertSee('All clear');

        $device->update(['disks' => diskAt(95.0)]);

        $dashboard->dispatch('echo-private:devices,DeviceUpdated', ['deviceId' => $device->id, 'status' => 'active'])
            ->assertSee('All clear');

        $this->travel(config('dashboard.heartbeat_refresh_seconds'))->seconds();

        $dashboard->dispatch('echo-private:devices,DeviceUpdated', ['deviceId' => $device->id, 'status' => 'active'])
            ->assertDontSee('All clear')
            ->assertSee('FILLING-UP');
    });

    it('rebuilds a disk bar with its new figure and colour when a heartbeat redraws', function (): void {
        $device = healthyDevice(['hostname' => 'FILLING-UP', 'disks' => diskAt(82.0)]);

        $dashboard = Livewire::actingAs($this->user)->test(Dashboard::class)
            ->assertSeeHtml('wire:key="usage-bar-82"')
            ->assertSeeHtml('--flux-progress-percentage: 82%')
            ->assertSeeHtml('var(--color-amber-600)');

        $device->update(['disks' => diskAt(93.0)]);
        $this->travel(config('dashboard.heartbeat_refresh_seconds'))->seconds();

        $dashboard->dispatch('echo-private:devices,DeviceUpdated', ['deviceId' => $device->id, 'status' => 'active'])
            ->assertSeeHtml('wire:key="usage-bar-93"')
            ->assertSeeHtml('--flux-progress-percentage: 93%')
            ->assertSeeHtml('var(--color-red-600)')
            ->assertDontSeeHtml('wire:key="usage-bar-82"');
    });

    it('refreshes recent activity from the audit channel', function (): void {
        $dashboard = Livewire::actingAs($this->user)->test(Dashboard::class)->assertDontSee('FRESH-ACTIVITY');

        $auditLog = AuditLog::factory()->create(['properties' => ['label' => 'FRESH-ACTIVITY']]);

        $dashboard->dispatch('echo-private:audit,AuditLogged', ['auditLogId' => $auditLog->id])
            ->assertSee('FRESH-ACTIVITY');
    });
});

it('runs the same number of queries however big the fleet is', function (): void {
    $grow = function (int $count): void {
        Device::factory()->count($count)->active()->create(['last_seen' => now(), 'agent_version' => '0.6.0'])
            ->each(function (Device $device): void {
                DeviceMetric::factory()->create(['device_id' => $device->id])
                    ->recordDisks([['mount_point' => '/', 'total_gb' => 100.0, 'available_gb' => 5.0]]);
                Alert::factory()->create(['device_id' => $device->id]);
            });
    };

    $queriesFor = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->user)->test(Dashboard::class);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    AuditLog::factory()->create(['user_id' => $this->user->id]);
    $grow(2);
    $smallFleet = $queriesFor();

    $grow(21);
    $bigFleet = $queriesFor();

    expect($bigFleet)->toBe($smallFleet);
});
