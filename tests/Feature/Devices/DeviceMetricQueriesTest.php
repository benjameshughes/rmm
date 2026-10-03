<?php

declare(strict_types=1);

use App\DTOs\MetricChart;
use App\Enums\MetricRange;
use App\Livewire\Devices\Metrics;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\User;
use App\Queries\DeviceMetricQueries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));
    $this->device = Device::factory()->active()->create();
});

function reportAt(string $time, array $attributes = []): DeviceMetric
{
    return DeviceMetric::factory()->create([
        'device_id' => test()->device->id,
        'recorded_at' => Carbon::parse($time, 'UTC'),
        ...$attributes,
    ]);
}

it('averages reports into buckets sized by the range and leaves empty buckets out', function (MetricRange $range, array $reports, array $expected): void {
    collect($reports)->each(fn (array $report) => reportAt($report[0], ['cpu' => $report[1]]));

    $rows = app(DeviceMetricQueries::class)->performance($this->device, $range);

    expect($rows->pluck('cpu', 'time')->all())->toBe($expected);
})->with([
    'last hour in minutes' => [MetricRange::OneHour, [['11:51:10', 10.0], ['11:51:50', 30.0], ['11:58:00', 50.0], ['10:30:00', 99.0]], ['2026-10-03T11:51:00Z' => 20.0, '2026-10-03T11:58:00Z' => 50.0]],
    'last day in ten minutes' => [MetricRange::OneDay, [['11:51:00', 10.0], ['11:58:00', 30.0], ['11:31:00', 50.0], ['2026-10-02 11:00:00', 99.0]], ['2026-10-03T11:30:00Z' => 50.0, '2026-10-03T11:50:00Z' => 20.0]],
    'last week in hours' => [MetricRange::OneWeek, [['2026-09-30 08:05:00', 10.0], ['2026-09-30 08:55:00', 20.0], ['2026-09-20 08:00:00', 99.0]], ['2026-09-30T08:00:00Z' => 15.0]],
]);

it('only charts the device asked for', function (): void {
    reportAt('11:55:00', ['cpu' => 10.0]);
    DeviceMetric::factory()->create(['recorded_at' => Carbon::parse('11:55:00', 'UTC'), 'cpu' => 90.0]);

    expect(app(DeviceMetricQueries::class)->performance($this->device, MetricRange::OneDay)->pluck('cpu')->all())->toBe([10.0]);
});

it('keeps nulls for what a device never reports and works out the page file percentage', function (): void {
    reportAt('11:55:00', ['load1' => null, 'cpu_queue_length' => 0.5, 'swap_used_mib' => 512.0, 'swap_total_mib' => 2048.0, 'disk_busy_percent' => null]);
    reportAt('11:56:00', ['load1' => null, 'cpu_queue_length' => 1.5, 'swap_used_mib' => 1024.0, 'swap_total_mib' => 2048.0, 'disk_busy_percent' => null]);

    $bucket = app(DeviceMetricQueries::class)->performance($this->device, MetricRange::OneDay)->sole();

    expect($bucket)
        ->load->toBeNull()
        ->diskBusy->toBeNull()
        ->cpuQueue->toBe(1.0)
        ->pageFile->toBe(37.5);
});

it('sums network throughput across adapters and averages it per report', function (): void {
    reportAt('11:55:00')->recordNetworkInterfaces([
        ['interface' => 'Ethernet', 'received_kbps' => 100.0, 'sent_kbps' => 10.0],
        ['interface' => 'Wi-Fi', 'received_kbps' => 50.0, 'sent_kbps' => 5.0],
    ]);
    reportAt('11:56:00')->recordNetworkInterfaces([
        ['interface' => 'Ethernet', 'received_kbps' => 250.0, 'sent_kbps' => 25.0],
    ]);

    expect(app(DeviceMetricQueries::class)->network($this->device, MetricRange::OneDay)->sole())->toBe([
        'time' => '2026-10-03T11:50:00Z',
        'received' => 200.0,
        'sent' => 20.0,
    ]);
});

it('drops series a device never reports and buckets with nothing to draw', function (): void {
    $rows = collect([
        ['time' => '2026-10-03T11:00:00Z', 'cpuQueue' => 0.5, 'load' => null],
        ['time' => '2026-10-03T11:10:00Z', 'cpuQueue' => null, 'load' => null],
        ['time' => '2026-10-03T11:20:00Z', 'cpuQueue' => 1.5, 'load' => null],
    ]);

    $chart = MetricChart::processorLoad($rows);

    expect(collect($chart->series)->pluck('field')->all())->toBe(['cpuQueue'])
        ->and($chart->points())->toBe([
            ['time' => '2026-10-03T11:00:00Z', 'cpuQueue' => 0.5],
            ['time' => '2026-10-03T11:20:00Z', 'cpuQueue' => 1.5],
        ])
        ->and($chart->isDrawable())->toBeTrue();
});

it('says there is nothing to draw rather than drawing one point or none', function (array $rows): void {
    expect(MetricChart::cpuAndMemory(collect($rows))->isDrawable())->toBeFalse();
})->with([
    'no reports' => [[]],
    'one bucket' => [[['time' => '2026-10-03T11:00:00Z', 'cpu' => 5.0, 'ram' => 40.0]]],
    'never reported' => [[['time' => '2026-10-03T11:00:00Z', 'cpu' => null, 'ram' => null], ['time' => '2026-10-03T11:10:00Z', 'cpu' => null, 'ram' => null]]],
]);

it('reads bucket sizes from config', function (): void {
    config(['devices.metrics.chart_ranges.24h.bucket_seconds' => 3600]);
    reportAt('11:05:00', ['cpu' => 10.0]);
    reportAt('11:55:00', ['cpu' => 30.0]);

    expect(app(DeviceMetricQueries::class)->performance($this->device, MetricRange::OneDay)->pluck('cpu', 'time')->all())
        ->toBe(['2026-10-03T11:00:00Z' => 20.0]);
});

describe('metrics tab', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
    });

    it('defaults to the last 24 hours', function (): void {
        Livewire::actingAs($this->user)->test(Metrics::class, ['device' => $this->device])
            ->assertSet('range', '24h')
            ->assertSee('Last 24 hours');
    });

    it('binds the range to the query string', function (string $range, string $label): void {
        Livewire::withQueryParams(['range' => $range])
            ->actingAs($this->user)
            ->test(Metrics::class, ['device' => $this->device])
            ->assertSet('range', $range)
            ->assertSee($label);
    })->with([
        ['1h', 'Last hour'],
        ['7d', 'Last 7 days'],
    ]);

    it('switches range and redraws the charts for it', function (): void {
        reportAt('11:51:10', ['cpu' => 10.0, 'ram' => 40.0]);
        reportAt('11:58:00', ['cpu' => 30.0, 'ram' => 50.0]);

        Livewire::actingAs($this->user)->test(Metrics::class, ['device' => $this->device])
            ->assertSee('Not enough reports in this range to draw a chart yet.')
            ->set('range', '1h')
            ->assertSee('Last hour')
            ->assertSeeHtml('2026-10-03T11:51:00Z')
            ->assertSeeHtml('2026-10-03T11:58:00Z');
    });

    it('falls back to the default range for a hand-edited query string', function (): void {
        $component = Livewire::withQueryParams(['range' => 'forever'])
            ->actingAs($this->user)
            ->test(Metrics::class, ['device' => $this->device])
            ->assertSuccessful()
            ->assertSee('Last 24 hours');

        expect($component->instance()->metricRange)->toBe(MetricRange::OneDay);
    });

    it('charts CPU queue on windows and load average on linux, never both', function (array $attributes, string $shown, string $hidden): void {
        reportAt('11:31:00', $attributes);
        reportAt('11:51:00', $attributes);

        Livewire::actingAs($this->user)->test(Metrics::class, ['device' => $this->device])
            ->assertViewHas('charts', fn (array $charts): bool => collect($charts[1]->series)->pluck('label')->all() === [$shown])
            ->assertDontSee($hidden);
    })->with([
        'windows' => [['load1' => null, 'cpu_queue_length' => 0.25], 'CPU queue', 'Load average'],
        'linux' => [['load1' => 1.5, 'cpu_queue_length' => null], 'Load average', 'CPU queue'],
    ]);
});
