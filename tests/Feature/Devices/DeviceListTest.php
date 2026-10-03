<?php

declare(strict_types=1);

use App\Enums\DevicePowerState;
use App\Enums\DeviceTab;
use App\Livewire\Devices\Index;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DeviceMetric;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

function fleet(): array
{
    return [
        'online' => Device::factory()->active()->create(['hostname' => 'UP-PC', 'last_seen' => now(), 'agent_version' => '0.6.5']),
        'asleep' => Device::factory()->active()->poweringOff()->create(['hostname' => 'ASLEEP-PC', 'last_seen' => now(), 'agent_version' => '0.6.5']),
        'offline' => Device::factory()->active()->create(['hostname' => 'GONE-PC', 'last_seen' => now()->subHour(), 'agent_version' => '0.6.0']),
        'pending' => Device::factory()->create(['hostname' => 'NEW-PC', 'last_seen' => now()]),
    ];
}

it('sums the fleet up in the summary strip', function (): void {
    Cache::forever(config('agent.latest_version_cache_key'), '0.6.5');
    $devices = fleet();
    Alert::factory()->triggered()->create(['device_id' => $devices['offline']->id]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertViewHas('summary', [
            'total' => 4,
            'online' => 1,
            'poweringOff' => 1,
            'offline' => 1,
            'outdated' => 1,
            'openAlerts' => 1,
        ]);
});

it('agrees between the model and the list filters on who is online, asleep or offline', function (array $attributes, string $scope): void {
    $device = Device::factory()->active()->create($attributes);

    $matches = collect(['online', 'poweringOff', 'offline'])
        ->filter(fn (string $candidate): bool => Device::query()->{$candidate}()->whereKey($device->id)->exists())
        ->values()
        ->all();

    expect($matches)->toBe([$scope])
        ->and($device->statusLabel())->toStartWith(['online' => 'Online', 'poweringOff' => 'Powering off', 'offline' => 'Offline'][$scope]);
})->with([
    'checked in' => [fn (): array => ['last_seen' => now()], 'online'],
    'announced sleep' => [fn (): array => ['last_seen' => now(), 'power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()], 'poweringOff'],
    'went quiet' => [fn (): array => ['last_seen' => now()->subHour()], 'offline'],
    'never seen' => [fn (): array => ['last_seen' => null], 'offline'],
    'sleep notice lapsed' => [fn (): array => ['last_seen' => now()->subDays(4), 'power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()->subDays(4)], 'offline'],
]);

it('filters the list from a summary card and toggles back to the whole fleet', function (string $filter, string $shown): void {
    Cache::forever(config('agent.latest_version_cache_key'), '0.6.5');
    fleet();

    $hidden = collect(['UP-PC', 'ASLEEP-PC', 'GONE-PC'])->reject(fn (string $hostname): bool => $hostname === $shown)->all();

    $list = Livewire::actingAs($this->user)->test(Index::class)
        ->call('filterByStatus', $filter)
        ->assertSet('statusFilter', $filter)
        ->assertSee($shown);

    collect($hidden)->each(fn (string $hostname) => $list->assertDontSee($hostname));

    $list->call('filterByStatus', $filter)
        ->assertSet('statusFilter', '')
        ->assertSee('UP-PC')
        ->assertSee('GONE-PC');
})->with([
    'online' => ['online', 'UP-PC'],
    'powering off' => ['powering-off', 'ASLEEP-PC'],
    'offline' => ['offline', 'GONE-PC'],
    'agent behind' => ['outdated', 'GONE-PC'],
]);

it('binds the status filter to the query string and ignores nonsense', function (): void {
    fleet();

    Livewire::withQueryParams(['statusFilter' => 'offline'])->actingAs($this->user)->test(Index::class)
        ->assertSee('GONE-PC')
        ->assertDontSee('UP-PC');

    Livewire::withQueryParams(['statusFilter' => 'sideways'])->actingAs($this->user)->test(Index::class)
        ->assertSuccessful()
        ->assertSee('GONE-PC')
        ->assertSee('UP-PC');
});

it('combines the status filter with search, group and tag filters and clears them all', function (): void {
    $group = DeviceGroup::factory()->create();
    Device::factory()->active()->create(['hostname' => 'SHOP-GONE', 'last_seen' => now()->subHour(), 'device_group_id' => $group->id]);
    Device::factory()->active()->create(['hostname' => 'SHOP-UP', 'last_seen' => now(), 'device_group_id' => $group->id]);
    Device::factory()->active()->create(['hostname' => 'OFFICE-GONE', 'last_seen' => now()->subHour()]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->set('groupFilter', (string) $group->id)
        ->set('search', 'SHOP')
        ->call('filterByStatus', 'offline')
        ->assertSee('SHOP-GONE')
        ->assertDontSee('SHOP-UP')
        ->assertDontSee('OFFICE-GONE')
        ->assertSee('Clear filters')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('groupFilter', '')
        ->assertSet('statusFilter', '')
        ->assertSee('OFFICE-GONE');
});

it('shows each row with its link, OS and IP, status pill, usage bars, agent and group and tags', function (): void {
    $group = DeviceGroup::factory()->create(['name' => 'Tills']);
    $tag = Tag::factory()->create(['name' => 'front-desk']);
    $device = Device::factory()->active()->create([
        'hostname' => 'TILL-01',
        'os_name' => 'Windows 11 Pro',
        'last_ip' => '10.0.30.21',
        'last_seen' => now()->subMinutes(2),
        'agent_version' => '0.6.5',
        'device_group_id' => $group->id,
        'disks' => [['name' => 'C:', 'total_gb' => 100.0, 'available_gb' => 8.0]],
    ]);
    $device->tags()->attach($tag);
    DeviceMetric::factory()->create(['device_id' => $device->id, 'cpu' => 93.4, 'ram' => 41.2]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSeeHtml('href="'.route('devices.show', $device).'"')
        ->assertSee('Windows 11 Pro')
        ->assertSee('10.0.30.21')
        ->assertSeeHtml('data-device-status="Offline"')
        ->assertSee('Seen 2 minutes ago')
        ->assertSeeInOrder(['CPU', '93%', 'RAM', '41%', 'C:', '92.0%'])
        ->assertSeeHtml('bg-red-500')
        ->assertSee('Agent 0.6.5')
        ->assertSee('Tills')
        ->assertSee('front-desk');
});

it('colours CPU and RAM bars from the configured thresholds', function (float $percent, string $color): void {
    $metric = DeviceMetric::factory()->make(['cpu' => $percent, 'ram' => $percent]);

    expect($metric->cpuBarColor())->toBe($color)
        ->and($metric->ramBarColor())->toBe($color);
})->with([
    'idle' => [20.0, 'bg-blue-500'],
    'busy' => [75.0, 'bg-amber-500'],
    'maxed' => [95.0, 'bg-red-500'],
]);

it('links each row to every device tab from its overflow menu', function (): void {
    $device = Device::factory()->active()->create();

    $list = Livewire::actingAs($this->user)->test(Index::class);

    collect(DeviceTab::cases())->each(fn (DeviceTab $tab) => $list->assertSeeHtml('href="'.route($tab->routeName(), $device).'"'));
});

it('offers Wake only on rows that can be woken', function (array $attributes, bool $isWakeable): void {
    $device = Device::factory()->active()->create($attributes);

    $list = Livewire::actingAs($this->user)->test(Index::class);

    $isWakeable
        ? $list->assertSeeHtml("wire:click=\"wake({$device->id})\"")
        : $list->assertDontSeeHtml("wire:click=\"wake({$device->id})\"");
})->with([
    'asleep with a MAC' => [fn (): array => ['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']], true],
    'online' => [fn (): array => ['last_seen' => now(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']], false],
    'asleep without a MAC' => [fn (): array => ['last_seen' => now()->subHour(), 'mac_addresses' => null], false],
]);

it('wakes a device from its row and toasts', function (): void {
    $listener = listenForWakePackets();
    $device = Device::factory()->active()->create(['hostname' => 'SLEEPY-PC', 'last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->call('wake', $device->id)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => $params['slots']['heading'] === 'Wake packet sent to SLEEPY-PC');

    expect(receivedWakePackets($listener))->toBe(array_fill(0, 3, magicPacketFor('AA:BB:CC:DD:EE:FF')));
});

it('checks the wake ability before waking from the list', function (): void {
    $listener = listenForWakePackets();
    $device = Device::factory()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);
    $list = Livewire::actingAs($this->user)->test(Index::class);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'wake' ? false : null);

    $list->call('wake', $device->id)->assertForbidden();

    expect(receivedWakePackets($listener))->toBeEmpty();
});

it('invites you to install the agent when there are no devices at all', function (): void {
    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee('No devices yet')
        ->assertSeeHtml('href="'.route('devices.agent').'"')
        ->assertDontSee('No devices match');
});

it('offers to clear the filters when nothing matches them', function (): void {
    Device::factory()->active()->create(['hostname' => 'ANY-PC']);

    Livewire::actingAs($this->user)->test(Index::class)
        ->set('search', 'NOTHING-LIKE-THIS')
        ->assertSee('No devices match these filters')
        ->assertDontSee('No devices yet')
        ->call('clearFilters')
        ->assertSee('ANY-PC');
});

it('refreshes the open alert count when an alert changes', function (): void {
    $device = Device::factory()->active()->create();

    $list = Livewire::actingAs($this->user)->test(Index::class)
        ->assertViewHas('summary', fn (array $summary): bool => $summary['openAlerts'] === 0);

    $alert = Alert::factory()->triggered()->create(['device_id' => $device->id]);

    $list->dispatch('echo-private:devices,AlertChanged', ['alertId' => $alert->id, 'deviceId' => $device->id, 'status' => 'triggered'])
        ->assertViewHas('summary', fn (array $summary): bool => $summary['openAlerts'] === 1);
});

it('runs the same number of queries however many devices the page shows', function (): void {
    $makeDevices = function (int $count): void {
        $group = DeviceGroup::factory()->create();
        $tag = Tag::factory()->create();

        Device::factory()->count($count)->active()->create(['device_group_id' => $group->id])
            ->each(function (Device $device) use ($tag): void {
                $device->tags()->attach($tag);
                DeviceMetric::factory()->create(['device_id' => $device->id])
                    ->recordDisks([['mount_point' => 'C:', 'total_gb' => 100.0, 'available_gb' => 40.0]]);
            });
    };

    $queriesFor = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->user)->test(Index::class);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $makeDevices(2);
    $fewDevices = $queriesFor();

    $makeDevices(8);
    $manyDevices = $queriesFor();

    expect($manyDevices)->toBe($fewDevices);
});
