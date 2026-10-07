<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Livewire\Trends\Index;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceMetric;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-07 12:00:00', 'UTC'));
    config(['trends.min_reports' => 3]);
    $this->user = User::factory()->create();
});

/**
 * Three reports in each week-long period with the given RAM, so the PC is comparable.
 */
function trendingPc(string $hostname, float $previousRam, float $currentRam, array $deviceAttributes = []): Device
{
    $device = Device::factory()->active()->windows()->create(['hostname' => $hostname, ...$deviceAttributes]);

    collect([['2026-09-25 09:00', $previousRam], ['2026-10-06 09:00', $currentRam]])
        ->each(fn (array $period) => collect(range(0, 2))->each(fn (int $index) => DeviceMetric::factory()->create([
            'device_id' => $device->id,
            'recorded_at' => Carbon::parse($period[0], 'UTC')->addMinutes($index * 5),
            'ram' => $period[1],
            'cpu' => 10.0,
            'uptime_seconds' => 900_000 + $index * 300,
        ])));

    return $device;
}

it('shows the fleet headline, the device rows and links each PC to its Metrics tab', function (): void {
    $device = trendingPc('SALES-PC', 41.0, 37.0);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee('Last 7 days against the 7 days before')
        ->assertSee('1 Windows PC that reported in both periods')
        ->assertSeeHtml('data-trend-metric="ram"')
        ->assertSeeInOrder(['RAM', '41%', '37%', '−10%'])
        ->assertSeeHtml('data-direction="improved"')
        ->assertSee('SALES-PC')
        ->assertSeeHtml('href="'.route('devices.metrics', ['device' => $device, 'range' => '7d']).'"')
        ->assertDontSeeHtml('data-trends-insufficient');
});

it('says when the previous period has too little history and shows the latest alone', function (): void {
    $device = Device::factory()->active()->windows()->create(['hostname' => 'NEW-PC']);
    collect(range(0, 2))->each(fn (int $index) => DeviceMetric::factory()->create(['device_id' => $device->id, 'recorded_at' => now()->subHours(2)->addMinutes($index), 'ram' => 30.0]));

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSeeHtml('data-trends-insufficient')
        ->assertSee('Not enough history for the previous period')
        ->assertSee('1 Windows PC that reported')
        ->assertSeeHtml('data-trend-note');
});

it('shows a calm empty state with no reports and no changes', function (): void {
    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSeeHtml('data-trends-empty')
        ->assertSee('Not enough reports yet')
        ->assertSeeHtml('data-trends-no-changes')
        ->assertDontSeeHtml('data-trends-devices');
});

it('lists what was done in the same period beside the figures, and on each device row', function (): void {
    app(SyncSystemScripts::class)();
    $first = trendingPc('ALPHA-PC', 50.0, 40.0);
    $second = trendingPc('BRAVO-PC', 50.0, 45.0);
    $uninstall = Script::findSystem('winget-uninstall');
    collect([$first, $second])->each(fn (Device $device) => DeviceCommand::factory()->create([
        'device_id' => $device->id,
        'script_id' => $uninstall->id,
        'parameters' => ['PackageId' => 'Datadog.Agent'],
        'status' => CommandStatus::Completed,
        'exit_code' => 0,
        'completed_at' => now()->subDay(),
    ]));

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee('In the same period')
        ->assertSee('Datadog.Agent uninstalled on 2 PCs')
        ->assertSeeHtml('href="'.route('software.show', ['id' => 'Datadog.Agent']).'"')
        ->assertSeeHtml("\$dispatch('show-command'")
        ->assertSee('Uninstalled Datadog.Agent')
        ->assertDontSee('because');
});

it('switches period and platform from the query string and ignores nonsense', function (): void {
    trendingPc('SALES-PC', 41.0, 37.0);

    Livewire::withQueryParams(['period' => '24h'])->actingAs($this->user)->test(Index::class)
        ->assertSee('Last 24 hours against the 24 hours before')
        ->set('period', '30d')
        ->assertSee('Last 30 days against the 30 days before')
        ->set('platform', 'all')
        ->assertSee('All devices');

    Livewire::withQueryParams(['period' => 'forever', 'platform' => 'mars', 'sort' => 'nope'])->actingAs($this->user)->test(Index::class)
        ->assertSee('Last 7 days against the 7 days before')
        ->assertSee('1 Windows PC that reported in both periods');
});

it('sorts devices by the biggest drop first, flips on a second click, and keeps uncomparable rows last', function (): void {
    trendingPc('SMALL-DROP', 50.0, 48.0);
    trendingPc('BIG-DROP', 50.0, 30.0);
    trendingPc('RISE', 50.0, 60.0);
    $newcomer = Device::factory()->active()->windows()->create(['hostname' => 'NEWCOMER']);
    collect(range(0, 2))->each(fn (int $index) => DeviceMetric::factory()->create(['device_id' => $newcomer->id, 'recorded_at' => now()->subHours(2)->addMinutes($index), 'ram' => 30.0]));

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSeeInOrder(['BIG-DROP', 'SMALL-DROP', 'RISE', 'NEWCOMER'])
        ->call('sort', 'ram')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeInOrder(['RISE', 'SMALL-DROP', 'BIG-DROP', 'NEWCOMER'])
        ->call('sort', 'pc')
        ->assertSeeInOrder(['BIG-DROP', 'NEWCOMER', 'RISE', 'SMALL-DROP']);
});

it('is in the sidebar, needs a login and respects the device policy', function (): void {
    $this->get(route('trends.index'))->assertRedirect(route('login'));

    $this->actingAs($this->user)->get(route('trends.index'))
        ->assertSuccessful()
        ->assertSeeInOrder(['Monitoring', 'Alerts', 'Trends'])
        ->assertSeeHtml('href="'.route('trends.index').'" data-current');

    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'viewAny' ? false : null);
    $this->actingAs($this->user)->get(route('trends.index'))->assertForbidden();
});

it('keeps a flat query count as the fleet grows', function (): void {
    app(SyncSystemScripts::class)();
    $restart = Script::findSystem('restart');
    $addPc = function () use ($restart): void {
        $device = trendingPc('PC-'.Str::random(6), 50.0, 40.0);
        DeviceCommand::factory()->create(['device_id' => $device->id, 'script_id' => $restart->id, 'status' => CommandStatus::Completed, 'exit_code' => 0, 'completed_at' => now()->subDay()]);
    };
    $queriesFor = function (): int {
        cache()->flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->user)->test(Index::class);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    collect(range(1, 2))->each(fn () => $addPc());
    $small = $queriesFor();

    collect(range(1, 8))->each(fn () => $addPc());

    expect($queriesFor())->toBe($small);
});
