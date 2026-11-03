<?php

declare(strict_types=1);

use App\Enums\AlertSeverity;
use App\Enums\ChartInk;
use App\Enums\SignalColor;
use App\Livewire\Dashboard;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\User;
use App\Queries\DashboardChartQueries;
use App\Queries\DashboardQueries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->device = Device::factory()->active()->create(['last_seen' => now()]);
});

it('counts alerts raised today against the day before', function (int $today, int $yesterday, string $label): void {
    Alert::factory()->count($today)->create(['device_id' => $this->device->id, 'triggered_at' => now()->subHours(2)]);
    Alert::factory()->count($yesterday)->create(['device_id' => $this->device->id, 'triggered_at' => now()->subHours(30)]);
    Alert::factory()->create(['device_id' => $this->device->id, 'triggered_at' => now()->subDays(3)]);

    $trend = app(DashboardChartQueries::class)->alertTrend();

    expect($trend->latest)->toBe($today)
        ->and($trend->previous)->toBe($yesterday)
        ->and($trend->deltaLabel())->toBe($label);
})->with([
    'more than yesterday' => [3, 1, '▲2 vs yesterday'],
    'fewer than yesterday' => [1, 4, '▼3 vs yesterday'],
    'the same' => [2, 2, 'same as yesterday'],
]);

it('buckets the last day of alerts by hour and severity, oldest first', function (): void {
    Alert::factory()->create(['device_id' => $this->device->id, 'severity' => AlertSeverity::Critical, 'triggered_at' => now()->subMinutes(10)]);
    Alert::factory()->count(2)->create(['device_id' => $this->device->id, 'severity' => AlertSeverity::Warning, 'triggered_at' => now()->subMinutes(20)]);
    Alert::factory()->create(['device_id' => $this->device->id, 'severity' => AlertSeverity::Warning, 'triggered_at' => now()->subHours(23)->subMinutes(30)]);
    Alert::factory()->create(['device_id' => $this->device->id, 'triggered_at' => now()->subHours(25)]);

    $buckets = app(DashboardChartQueries::class)->alertsPerBucket();

    expect($buckets)->toHaveCount(24)
        ->and($buckets->last())->toMatchArray(['warning' => 2, 'critical' => 1, 'total' => 3])
        ->and($buckets->first()['warning'])->toBe(1)
        ->and($buckets->sum('total'))->toBe(4);
});

it('averages each device\'s cpu into half-hour buckets in one query, with gaps left empty', function (): void {
    $other = Device::factory()->active()->create();
    DeviceMetric::factory()->create(['device_id' => $this->device->id, 'cpu' => 20.0, 'recorded_at' => now()->subMinutes(5)]);
    DeviceMetric::factory()->create(['device_id' => $this->device->id, 'cpu' => 40.0, 'recorded_at' => now()->subMinutes(15)]);
    DeviceMetric::factory()->create(['device_id' => $other->id, 'cpu' => 90.0, 'recorded_at' => now()->subHours(23)->subMinutes(50)]);

    DB::enableQueryLog();
    $sparklines = app(DashboardChartQueries::class)->cpuSparklines([$this->device->id, $other->id]);
    expect(DB::getQueryLog())->toHaveCount(1);

    expect($sparklines->get($this->device->id))->toHaveCount(48)
        ->and($sparklines->get($this->device->id)->last()['cpu'])->toBe(30.0)
        ->and($sparklines->get($this->device->id)->first()['cpu'])->toBeNull()
        ->and($sparklines->get($other->id)->first()['cpu'])->toBe(90.0);
});

it('draws the hero ring, fleet grid, alert bars and busiest sparklines', function (): void {
    Alert::factory()->create(['device_id' => $this->device->id, 'triggered_at' => now()->subHour()]);
    DeviceMetric::factory()->create(['device_id' => $this->device->id, 'cpu' => 50.0, 'ram' => 40.0]);

    Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
        ->assertSeeHtml('data-hero-online')
        ->assertSeeHtml('stroke-green-500')
        ->assertSeeHtml('data-fleet-square')
        ->assertSeeHtml('data-alerts-chart')
        ->assertViewHas('hero', fn ($hero): bool => $hero->total === 1 && $hero->online === 1 && $hero->alertTrend->latest === 1)
        ->assertViewHas('busiestRows', fn ($rows): bool => $rows->first()->sparkline->count() === 48);
});

it('averages fleet cpu and ram per half hour in one query', function (): void {
    $other = Device::factory()->active()->create();
    DeviceMetric::factory()->create(['device_id' => $this->device->id, 'cpu' => 20.0, 'ram' => 60.0, 'recorded_at' => now()->subMinutes(5)]);
    DeviceMetric::factory()->create(['device_id' => $other->id, 'cpu' => 40.0, 'ram' => 80.0, 'recorded_at' => now()->subMinutes(10)]);

    DB::enableQueryLog();
    $load = app(DashboardChartQueries::class)->fleetLoad([$this->device->id, $other->id]);
    expect(DB::getQueryLog())->toHaveCount(1);

    expect($load)->toHaveCount(48)
        ->and($load->last())->toMatchArray(['cpu' => 30.0, 'ram' => 70.0])
        ->and($load->first()['cpu'])->toBeNull();
});

it('rings the fleet by agent: current, behind and unknown, with behind as the problem', function (): void {
    Device::factory()->active()->count(3)->create(['agent_version' => '0.10.0']);
    Device::factory()->active()->create(['agent_version' => '0.9.2']);
    $this->device->forceFill(['agent_version' => null])->save();

    $ring = app(DashboardQueries::class)->agentRing(app(DashboardQueries::class)->fleet(), '0.10.0');

    expect($ring->segments->map(fn ($segment): array => [$segment->label, $segment->value])->all())->toBe([['Current', 3], ['Behind', 1], ['Unknown', 1]])
        ->and($ring->problemTotal())->toBe(1)
        ->and($ring->arcs()->pluck('length')->all())->toBe([60.0, 20.0, 20.0])
        ->and($ring->arcs()->pluck('offset')->all())->toBe([0.0, -60.0, -80.0]);
});

it('rings each device by its fullest disk, grey under the warning line, amber then red over it', function (): void {
    config(['dashboard.disk_bands' => [50], 'devices.disk.warning_percent' => 80, 'devices.disk.critical_percent' => 90]);
    $this->device->delete();
    collect([30.0, 60.0, 85.0, 95.0])->each(fn (float $used): Device => Device::factory()->active()->create(['disks' => [['name' => 'C:', 'total_gb' => 100.0, 'available_gb' => 100.0 - $used]]]));

    $ring = app(DashboardQueries::class)->diskRing(app(DashboardQueries::class)->fleet());

    expect($ring->segments->map(fn ($segment): array => [$segment->label, $segment->value])->all())->toBe([['0–50%', 1], ['50–80%', 1], ['80–90%', 1], ['90%+', 1]])
        ->and($ring->segments[2]->ink)->toBe(ChartInk::Warning)
        ->and($ring->segments[3]->ink)->toBe(ChartInk::Critical)
        ->and($ring->problemTotal())->toBe(2);
});

it('lists up to three offline devices on the hero tile, then how many more', function (): void {
    config(['dashboard.offline_names' => 2]);
    collect(range(1, 4))->each(fn (int $number): Device => Device::factory()->active()->create(['hostname' => "GONE-{$number}", 'last_seen' => now()->subHours($number)]));

    Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
        ->assertViewHas('offlineNames', fn ($devices): bool => $devices->pluck('hostname')->all() === ['GONE-1', 'GONE-2'])
        ->assertViewHas('hiddenOfflineCount', 2)
        ->assertSee('+2 more');
});

it('greys every mini bar except the current hour, which turns red when it raised a critical', function (): void {
    Alert::factory()->create(['device_id' => $this->device->id, 'severity' => AlertSeverity::Critical, 'triggered_at' => now()->subMinutes(5)]);
    Alert::factory()->create(['device_id' => $this->device->id, 'severity' => AlertSeverity::Warning, 'triggered_at' => now()->subHours(5)]);

    $charts = app(DashboardChartQueries::class);
    $bars = $charts->alertMiniBars($charts->alertsPerBucket());

    expect($bars->last())->toMatchArray(['calm' => 0, 'hot' => 1])
        ->and($bars->sum('calm'))->toBe(1);
});

it('builds the hero meters with problems first and caps them', function (): void {
    config(['dashboard.hero_segments' => 2]);
    $this->device->delete();
    collect([10.0, 20.0, 95.0])->each(fn (float $used): Device => Device::factory()->active()->create(['last_seen' => now(), 'disks' => [['name' => 'C:', 'total_gb' => 100.0, 'available_gb' => 100.0 - $used]]]));

    Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
        ->assertViewHas('heroMeters', fn (array $meters): bool => $meters['disks']->all() === [ChartInk::Critical, ChartInk::Faint]);
});

it('labels each fleet grid group with its count and offline devices, and explains each square in a tooltip', function (): void {
    $this->device->delete();
    Device::factory()->active()->windows()->create(['last_seen' => now()]);
    Device::factory()->active()->linux()->create(['last_seen' => now()->subHour()]);

    Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
        ->assertViewHas('fleetGrid', fn ($groups): bool => $groups->map(fn ($group): string => $group->label())->all() === ['Windows 1', 'Linux 1 · 1 offline'])
        ->assertDontSeeHtml('data-fleet-legend')
        ->assertSeeHtml('data-flux-tooltip')
        ->assertSee('Seen 1 hour ago');
});

it('colours a fleet square red when offline, amber when it needs attention, and green when online and fine', function (): void {
    $this->device->delete();
    Device::factory()->active()->windows()->create(['hostname' => 'FINE', 'last_seen' => now(), 'agent_version' => null]);
    Device::factory()->active()->windows()->create(['hostname' => 'FULL', 'last_seen' => now(), 'agent_version' => null, 'disks' => [['name' => 'C:', 'total_gb' => 100.0, 'available_gb' => 3.0]]]);
    Device::factory()->active()->windows()->create(['hostname' => 'GONE', 'last_seen' => now()->subHour(), 'agent_version' => null, 'disks' => [['name' => 'C:', 'total_gb' => 100.0, 'available_gb' => 3.0]]]);

    Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
        ->assertViewHas('fleetGrid', fn ($groups): bool => $groups->first()->squares->mapWithKeys(fn ($square): array => [$square->device->hostname => $square->signal->value])->all() === [
            'FINE' => SignalColor::Green->value,
            'FULL' => SignalColor::Amber->value,
            'GONE' => SignalColor::Red->value,
        ])
        ->assertDontSeeHtml('outline-amber-500');
});

it('fades zero problem figures and colours the rest by their signal', function (): void {
    expect(SignalColor::Red->figureClass(0))->toBe('text-zinc-300 dark:text-zinc-600')
        ->and(SignalColor::Red->figureClass(2))->toBe(SignalColor::Red->textClass())
        ->and(SignalColor::fromFluxColor('blue'))->toBe(SignalColor::Zinc);
});
