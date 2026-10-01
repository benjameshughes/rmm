<?php

declare(strict_types=1);

use App\Livewire\Devices\Show;
use App\Models\Device;
use App\Models\DeviceAppMetric;
use App\Models\DeviceMetric;
use App\Models\DeviceNetworkMetric;
use App\Models\User;
use Livewire\Livewire;

function reportFromWindowsAgent(string $apiKey): void
{
    test()->withHeaders(['X-Agent-Key' => $apiKey])
        ->postJson('/api/metrics', [
            'hostname' => 'DESKTOP-5ULJ14E',
            'timestamp' => now()->toIso8601String(),
            'agent_version' => '0.6.0',
            ...netdataWindowsFixture(),
        ])
        ->assertSuccessful();
}

it('shows performance, network adapters and top apps for a windows device', function (): void {
    $device = Device::factory()->withApiKey('KEY-PAGE')->create(['hostname' => 'DESKTOP-5ULJ14E']);
    reportFromWindowsAgent('KEY-PAGE');

    Livewire::actingAs(User::factory()->create())
        ->test(Show::class, ['device' => $device])
        ->assertDontSee('Load Average')
        ->assertSee('CPU Queue')
        ->assertSee('0.27')
        ->assertSee('Performance')
        ->assertSee('32.0%')
        ->assertSee('5.3 GB / 16.7 GB')
        ->assertSee('0.8%')
        ->assertSee('Network Adapters')
        ->assertSee('Intel[R] Ethernet Connection [17] I219-LM')
        ->assertSee('1 Gbps')
        ->assertSee('14 kbps')
        ->assertSee('6.9 kbps')
        ->assertSee('Top Apps')
        ->assertSeeInOrder(['Netdata Agent', '2.2%', '106.8 MB'])
        ->assertSee('Dell TechHub')
        ->assertSee('484.7 MB');
});

it('keeps load average and hides the windows sections for a linux device', function (): void {
    $device = Device::factory()->active()->create();
    DeviceMetric::factory()->create(['device_id' => $device->id, 'load1' => 1.5, 'load5' => 1.25, 'load15' => 1.0]);

    Livewire::actingAs(User::factory()->create())
        ->test(Show::class, ['device' => $device])
        ->assertSee('Load Average')
        ->assertSee('1.50')
        ->assertSee('1.25 / 1.00')
        ->assertDontSee('CPU Queue')
        ->assertDontSee('Performance')
        ->assertDontSee('Network Adapters')
        ->assertDontSee('Top Apps');
});

it('eager loads every section of the latest report on mount and on live refresh', function (): void {
    $device = Device::factory()->withApiKey('KEY-LIVE')->create();

    $component = Livewire::actingAs(User::factory()->create())
        ->test(Show::class, ['device' => $device])
        ->assertDontSee('Network Adapters');

    reportFromWindowsAgent('KEY-LIVE');

    $component->call('refreshDevice')->assertSee('Network Adapters')->assertSee('Top Apps');

    $latest = $component->get('device')->latestMetric;
    expect($latest->relationLoaded('diskMetrics'))->toBeTrue()
        ->and($latest->relationLoaded('networkMetrics'))->toBeTrue()
        ->and($latest->relationLoaded('appMetrics'))->toBeTrue();
});

it('highlights adapters with errors or drops', function (): void {
    $healthy = DeviceNetworkMetric::make(['interface' => 'Ethernet', 'errors_inbound' => 0.0, 'errors_outbound' => 0.0, 'drops_inbound' => 0.0, 'drops_outbound' => 0.0]);
    $faulty = DeviceNetworkMetric::make(['interface' => 'Wi-Fi', 'errors_inbound' => 0.25, 'errors_outbound' => 0.0, 'drops_inbound' => 0.0, 'drops_outbound' => 1.5]);

    expect($healthy->errorsColor())->toBe('zinc')
        ->and($healthy->dropsColor())->toBe('zinc')
        ->and($healthy->errorsForHumans())->toBe('0 / 0')
        ->and($faulty->errorsColor())->toBe('red')
        ->and($faulty->dropsColor())->toBe('amber')
        ->and($faulty->errorsForHumans())->toBe('0.25 / 0')
        ->and($faulty->dropsForHumans())->toBe('0 / 1.5');
});

it('humanises link speeds and throughput', function (int|float $kbps, string $expected): void {
    expect(DeviceNetworkMetric::make(['link_speed_kbps' => $kbps])->linkSpeedForHumans())->toBe($expected);
})->with([
    'gigabit' => [1000000, '1 Gbps'],
    '2.5 gigabit' => [2500000, '2.5 Gbps'],
    'fast ethernet' => [100000, '100 Mbps'],
    'trickle' => [14.0066, '14 kbps'],
]);

it('renders the network adapters component from its props', function (): void {
    $adapters = collect([DeviceNetworkMetric::make([
        'interface' => 'Wi-Fi',
        'received_kbps' => 2500.0,
        'sent_kbps' => 640.0,
        'errors_inbound' => 0.5,
        'errors_outbound' => 0.0,
        'link_speed_kbps' => 866000,
    ])]);

    $this->blade('<x-device.network-adapters :adapters="$adapters" />', ['adapters' => $adapters])
        ->assertSee('Network Adapters')
        ->assertSee('Wi-Fi')
        ->assertSee('866 Mbps')
        ->assertSee('2.5 Mbps')
        ->assertSee('640 kbps')
        ->assertSee('0.5 / 0');

    $this->blade('<x-device.network-adapters :adapters="$adapters" />', ['adapters' => collect()])
        ->assertDontSee('Network Adapters');
});

it('renders the top apps component from its props', function (): void {
    $apps = collect([DeviceAppMetric::make(['name' => 'Dell TechHub', 'cpu_percent' => 0.15, 'memory_mib' => 2048.0])]);

    $this->blade('<x-device.top-apps :apps="$apps" />', ['apps' => $apps])
        ->assertSee('Top Apps')
        ->assertSee('Dell TechHub')
        ->assertSee('0.2%')
        ->assertSee('2.0 GB');

    $this->blade('<x-device.top-apps :apps="$apps" />', ['apps' => null])->assertDontSee('Top Apps');
});

it('renders the performance and summary components from a metric', function (): void {
    $metric = DeviceMetric::factory()->make([
        'cpu' => 12.5,
        'load1' => null,
        'cpu_queue_length' => 3.0,
        'swap_used_mib' => 512.0,
        'swap_total_mib' => 2048.0,
        'disk_busy_percent' => 55.55,
    ]);

    $this->blade('<x-device.stats.performance :metric="$metric" />', ['metric' => $metric])
        ->assertSee('Performance')
        ->assertSee('3.00')
        ->assertSee('25.0%')
        ->assertSee('512.0 MB / 2.0 GB')
        ->assertSee('55.6%');

    $this->blade('<x-device.stats.summary :metric="$metric" />', ['metric' => $metric])
        ->assertSee('12.5%')
        ->assertSee('CPU Queue')
        ->assertDontSee('Load Average');

    $this->blade('<x-device.stats.summary :metric="$metric" />', ['metric' => null])
        ->assertSee('Load Average')
        ->assertSee('—');
});
