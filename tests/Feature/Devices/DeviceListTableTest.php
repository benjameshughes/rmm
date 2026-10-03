<?php

declare(strict_types=1);

use App\Actions\Device\AnnounceDevicesGoneOffline;
use App\Enums\DevicePowerState;
use App\Enums\DeviceStatus;
use App\Events\DeviceUpdated;
use App\Livewire\Devices\Index;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

/**
 * Three devices whose every sortable value differs, plus one with nothing reported.
 *
 * @return array<string, Device>
 */
function sortableFleet(): array
{
    $alpha = DeviceGroup::factory()->create(['name' => 'Alpha']);
    $zulu = DeviceGroup::factory()->create(['name' => 'Zulu']);

    $devices = [
        'BRAVO' => Device::factory()->active()->create(['hostname' => 'BRAVO', 'device_group_id' => $zulu->id, 'agent_version' => '0.6.0', 'last_seen' => now(), 'disks' => null]),
        'ALPHA' => Device::factory()->active()->create(['hostname' => 'ALPHA', 'device_group_id' => $alpha->id, 'agent_version' => '0.7.1', 'last_seen' => now()->subHours(3), 'disks' => null]),
        'CHARLIE' => Device::factory()->active()->create(['hostname' => 'CHARLIE', 'agent_version' => '0.6.5', 'last_seen' => now(), 'power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now(), 'disks' => null]),
        'DELTA' => Device::factory()->create(['hostname' => 'DELTA', 'agent_version' => null, 'last_seen' => null, 'disks' => null]),
    ];

    collect(['BRAVO' => [50.0, 20.0, 30.0], 'ALPHA' => [10.0, 90.0, 95.0], 'CHARLIE' => [99.0, 60.0, 60.0]])
        ->each(fn (array $figures, string $hostname) => DeviceMetric::factory()->create(['device_id' => $devices[$hostname]->id, 'cpu' => $figures[0], 'ram' => $figures[1]])
            ->recordDisks([['mount_point' => 'C:', 'total_gb' => 100.0, 'available_gb' => 100.0 - $figures[2]]]));

    return $devices;
}

function listedHostnames(Testable $list): array
{
    return $list->viewData('devices')->pluck('hostname')->all();
}

it('sorts by hostname A to Z by default, whatever was seen last', function (): void {
    sortableFleet();

    $list = Livewire::actingAs($this->user)->test(Index::class)
        ->assertSet('sortBy', 'hostname')
        ->assertSet('sortDirection', 'asc');

    expect(listedHostnames($list))->toBe(['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA']);
});

it('sorts by each column both ways, with empty values last', function (string $column, array $ascending, array $descending): void {
    sortableFleet();

    $asc = Livewire::withQueryParams(['sort' => $column, 'direction' => 'asc'])->actingAs($this->user)->test(Index::class);
    $desc = Livewire::withQueryParams(['sort' => $column, 'direction' => 'desc'])->actingAs($this->user)->test(Index::class);

    expect(listedHostnames($asc))->toBe($ascending)
        ->and(listedHostnames($desc))->toBe($descending);
})->with([
    'hostname' => ['hostname', ['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA'], ['DELTA', 'CHARLIE', 'BRAVO', 'ALPHA']],
    'status' => ['status', ['BRAVO', 'CHARLIE', 'ALPHA', 'DELTA'], ['DELTA', 'ALPHA', 'CHARLIE', 'BRAVO']],
    'group' => ['group', ['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA'], ['BRAVO', 'ALPHA', 'CHARLIE', 'DELTA']],
    'cpu' => ['cpu', ['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA'], ['CHARLIE', 'BRAVO', 'ALPHA', 'DELTA']],
    'ram' => ['ram', ['BRAVO', 'CHARLIE', 'ALPHA', 'DELTA'], ['ALPHA', 'CHARLIE', 'BRAVO', 'DELTA']],
    'disk' => ['disk', ['BRAVO', 'CHARLIE', 'ALPHA', 'DELTA'], ['ALPHA', 'CHARLIE', 'BRAVO', 'DELTA']],
    'agent' => ['agent', ['BRAVO', 'CHARLIE', 'ALPHA', 'DELTA'], ['ALPHA', 'CHARLIE', 'BRAVO', 'DELTA']],
    'last seen' => ['last-seen', ['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA'], ['BRAVO', 'CHARLIE', 'ALPHA', 'DELTA']],
]);

it('flips the sorted column and starts a new one in its natural direction', function (): void {
    sortableFleet();

    $list = Livewire::actingAs($this->user)->test(Index::class)
        ->call('sort', 'hostname')
        ->assertSet('sortDirection', 'desc')
        ->call('sort', 'cpu')
        ->assertSet('sortBy', 'cpu')
        ->assertSet('sortDirection', 'desc')
        ->call('sort', 'group')
        ->assertSet('sortDirection', 'asc');

    expect(listedHostnames($list))->toBe(['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA']);
});

it('falls back to hostname A to Z for a hand-edited query string', function (): void {
    sortableFleet();

    $list = Livewire::withQueryParams(['sort' => 'favourite-colour', 'direction' => 'sideways'])->actingAs($this->user)->test(Index::class)
        ->assertSuccessful();

    expect(listedHostnames($list))->toBe(['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA'])
        ->and($list->instance()->listSortDirection)->toBe('asc');
});

it('breaks ties by hostname so equal rows never swap places', function (): void {
    collect(['ECHO', 'ALPHA', 'CHARLIE'])->each(fn (string $hostname) => DeviceMetric::factory()->create([
        'device_id' => Device::factory()->active()->create(['hostname' => $hostname, 'last_seen' => now()])->id,
        'cpu' => 50.0,
    ]));

    $first = Livewire::withQueryParams(['sort' => 'cpu', 'direction' => 'desc'])->actingAs($this->user)->test(Index::class);
    $again = Livewire::withQueryParams(['sort' => 'cpu', 'direction' => 'desc'])->actingAs($this->user)->test(Index::class);

    expect(listedHostnames($first))->toBe(['ALPHA', 'CHARLIE', 'ECHO'])
        ->and(listedHostnames($again))->toBe(listedHostnames($first));
});

it('marks the sorted column header and hides minor columns on small screens', function (): void {
    sortableFleet();

    Livewire::withQueryParams(['sort' => 'ram', 'direction' => 'desc'])->actingAs($this->user)->test(Index::class)
        ->assertSeeHtml("wire:click=\"sort('ram')\"")
        ->assertSeeInOrder(['Device', 'Status', 'Group', 'CPU', 'RAM', 'Disk', 'Agent', 'Last seen'])
        ->assertSeeHtml('hidden lg:table-cell')
        ->assertSeeHtml('hidden xl:table-cell')
        ->assertSeeHtml('hidden 2xl:table-cell');
});

it('shows small coloured figures at the configured thresholds', function (): void {
    $device = Device::factory()->active()->create(['last_seen' => now(), 'disks' => null]);
    DeviceMetric::factory()->create(['device_id' => $device->id, 'cpu' => 95.0, 'ram' => 75.0])
        ->recordDisks([['mount_point' => 'D:', 'total_gb' => 100.0, 'available_gb' => 50.0]]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSeeInOrder(['95%', '75%', '50%'])
        ->assertSeeHtml('text-red-600')
        ->assertSeeHtml('text-amber-600');
});

it('shows last seen coarsely with the exact time on hover', function (array $attributes, string $shown): void {
    $device = Device::factory()->active()->create($attributes);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee($shown)
        ->assertSeeHtml('title="'.$device->lastSeenAt().'"');
})->with([
    'online' => [fn (): array => ['last_seen' => now()->subSeconds(20)], 'Now'],
    'offline' => [fn (): array => ['last_seen' => now()->subHours(3)], '3 hours ago'],
    'never' => [fn (): array => ['last_seen' => null], 'Never'],
]);

it('keeps the summary as one slim strip that still filters', function (): void {
    sortableFleet();

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSeeHtml('data-summary-strip')
        ->call('filterByStatus', 'offline')
        ->assertSet('statusFilter', 'offline')
        ->assertViewHas('devices', fn ($devices): bool => $devices->pluck('hostname')->all() === ['ALPHA']);
});

describe('live refresh', function (): void {
    it('holds routine metrics reports back until the list is stale', function (): void {
        $device = Device::factory()->active()->create(['last_seen' => now(), 'disks' => null]);
        DeviceMetric::factory()->create(['device_id' => $device->id, 'cpu' => 11.0]);

        $list = Livewire::actingAs($this->user)->test(Index::class)->assertSee('11%');

        $this->travel(1)->minute();
        DeviceMetric::factory()->create(['device_id' => $device->id, 'cpu' => 87.0]);
        $this->travelBack();

        $list->dispatch('echo-private:devices,DeviceUpdated', ['deviceId' => $device->id, 'status' => 'active', 'isStateChange' => false])
            ->assertSee('11%')
            ->assertDontSee('87%');

        $this->travel(config('devices.list.refresh_seconds') + 1)->seconds();

        $list->dispatch('echo-private:devices,DeviceUpdated', ['deviceId' => $device->id, 'status' => 'active', 'isStateChange' => false])
            ->assertSee('87%');
    });

    it('redraws at once for a state change, an older payload or an enrolment', function (string $event, array $payload): void {
        $device = Device::factory()->active()->create(['hostname' => 'FLIPPER', 'last_seen' => now()->subHour()]);

        $list = Livewire::actingAs($this->user)->test(Index::class)->assertSeeHtml('data-device-status="Offline"');

        $device->update(['last_seen' => now()]);
        Device::factory()->create(['hostname' => 'NEWCOMER']);

        $list->dispatch($event, ['deviceId' => $device->id, ...$payload])
            ->assertSeeHtml('data-device-status="Online"')
            ->assertSee('NEWCOMER');
    })->with([
        'state change' => ['echo-private:devices,DeviceUpdated', ['status' => 'active', 'isStateChange' => true]],
        'payload without the flag' => ['echo-private:devices,DeviceUpdated', ['status' => 'active']],
        'enrolment' => ['echo-private:devices,DeviceEnrolled', []],
    ]);

    it('restarts the quiet period after any redraw', function (): void {
        $device = Device::factory()->active()->create(['last_seen' => now(), 'disks' => null]);
        DeviceMetric::factory()->create(['device_id' => $device->id, 'cpu' => 11.0]);

        $list = Livewire::actingAs($this->user)->test(Index::class);

        $this->travel(config('devices.list.refresh_seconds') + 1)->seconds();
        $list->set('search', '');

        DeviceMetric::factory()->create(['device_id' => $device->id, 'cpu' => 87.0, 'recorded_at' => now()->addMinute()]);

        $list->dispatch('echo-private:devices,DeviceUpdated', ['deviceId' => $device->id, 'status' => 'active', 'isStateChange' => false])
            ->assertDontSee('87%');
    });
});

describe('what DeviceUpdated calls a state change', function (): void {
    it('treats a routine metrics report as routine', function (): void {
        $device = Device::factory()->active()->withApiKey('ROUTINE-KEY')->create(['last_seen' => now()->subSeconds(20)]);
        Event::fake([DeviceUpdated::class]);

        $this->withHeaders(['X-Agent-Key' => 'ROUTINE-KEY'])->postJson('/api/metrics', ['cpu' => ['usage_percent' => 5]])->assertSuccessful();

        Event::assertDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => $event->deviceId === $device->id
            && $event->broadcastWhen()
            && $event->broadcastWith()['isStateChange'] === false);
    });

    it('treats a report from a device that was offline as a state change', function (): void {
        $device = Device::factory()->active()->withApiKey('WAKING-KEY')->create(['last_seen' => now()->subHour()]);
        Event::fake([DeviceUpdated::class]);

        $this->withHeaders(['X-Agent-Key' => 'WAKING-KEY'])->postJson('/api/metrics', ['cpu' => ['usage_percent' => 5]])->assertSuccessful();

        Event::assertDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => $event->deviceId === $device->id
            && $event->broadcastWith()['isStateChange'] === true);
    });

    it('treats going offline and approval as state changes', function (): void {
        $quiet = Device::factory()->active()->create(['last_seen' => now()->subSeconds(config('devices.heartbeat.interval_seconds') * config('devices.online.missed_heartbeats') + 5)]);
        $pending = Device::factory()->create(['status' => DeviceStatus::Pending]);
        Event::fake([DeviceUpdated::class]);

        app(AnnounceDevicesGoneOffline::class)();
        $pending->issueApiKey();

        Event::assertDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => $event->deviceId === $quiet->id && $event->isStateChange);
        Event::assertDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => $event->deviceId === $pending->id && $event->isStateChange);
    });
});

it('keeps the query count flat as the fleet grows, whichever column is sorted', function (string $column): void {
    $queriesFor = function () use ($column): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['sort' => $column])->actingAs($this->user)->test(Index::class);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    sortableFleet();
    $few = $queriesFor();

    collect(range(1, 8))->each(fn (int $index) => DeviceMetric::factory()->create(['device_id' => Device::factory()->active()->create(['hostname' => "EXTRA-{$index}", 'last_seen' => now()])->id])
        ->recordDisks([['mount_point' => 'C:', 'total_gb' => 100.0, 'available_gb' => 40.0]]));

    expect($queriesFor())->toBe($few);
})->with(['hostname', 'status', 'disk']);
