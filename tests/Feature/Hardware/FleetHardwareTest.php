<?php

declare(strict_types=1);

use App\Events\SystemInventorySynced;
use App\Livewire\Hardware\Index;
use App\Models\Device;
use App\Models\DeviceInventory;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

function inventoriedPc(array $deviceAttributes = [], array $inventoryAttributes = []): Device
{
    $device = Device::factory()->active()->windows()->create(['system_inventoried_at' => now(), ...$deviceAttributes]);
    DeviceInventory::factory()->create(['device_id' => $device->id, ...$inventoryAttributes]);

    return $device;
}

it('lists each inventoried device with its model, service tag, CPU, RAM, disk, Windows and monitors', function (): void {
    $device = inventoriedPc(['hostname' => 'SALES-LAPTOP']);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee('SALES-LAPTOP')
        ->assertSeeHtml('href="'.route('devices.system', $device).'"')
        ->assertSee('Dell Inc. Latitude 5440')
        ->assertSee('7HQ2KZ3')
        ->assertSee('13th Gen Intel(R) Core(TM) i5-1345U')
        ->assertSee('16 GB')
        ->assertSee('477 GB SSD')
        ->assertSee('Windows 11 Pro')
        ->assertSee('22631.4317')
        ->assertSee('2 · DELL P2422H');
});

it('uses only the latest inventory of each device', function (): void {
    $device = inventoriedPc(inventoryAttributes: ['collected_at' => now()->subDays(3), 'serial_number' => 'OLDTAG1', 'total_ram_gb' => 8]);
    DeviceInventory::factory()->create(['device_id' => $device->id, 'collected_at' => now(), 'serial_number' => 'NEWTAG1', 'total_ram_gb' => 32]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertViewHas('rows', fn ($rows): bool => $rows->total() === 1)
        ->assertSee('NEWTAG1')
        ->assertSee('32 GB')
        ->assertDontSee('OLDTAG1');
});

it('leaves out devices without an inventory and counts the Windows ones', function (): void {
    inventoriedPc(['hostname' => 'HAS-INVENTORY']);
    Device::factory()->active()->windows()->count(2)->create(['hostname' => 'NO-INVENTORY']);
    Device::factory()->active()->create(['os' => 'Ubuntu 24.04', 'os_name' => 'Ubuntu', 'kernel_name' => 'Linux']);
    Device::factory()->active()->windows()->monitorOnly()->create();

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee('HAS-INVENTORY')
        ->assertDontSee('NO-INVENTORY')
        ->assertSee('2 devices have no system inventory yet.');
});

it('explains the empty page and hides the missing note when every device has an inventory', function (): void {
    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSeeHtml('data-hardware-empty')
        ->assertSee('No inventory yet')
        ->assertDontSeeHtml('data-hardware-missing');
});

it('searches by service tag, model, CPU and monitor', function (string $search): void {
    inventoriedPc(['hostname' => 'DELL-PC']);
    inventoriedPc(['hostname' => 'HP-PC'], ['manufacturer' => 'HP', 'model' => 'EliteDesk 800 G6', 'serial_number' => 'CZC1234XYZ', 'data' => ['cpu' => ['name' => 'Intel Core i7-10700'], 'monitors' => [['name' => 'HP E24 G5']]]]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->set('search', $search)
        ->assertSee('HP-PC')
        ->assertDontSee('DELL-PC');
})->with([
    'service tag' => 'czc1234',
    'model' => 'EliteDesk',
    'CPU' => 'i7-10700',
    'monitor' => 'hp e24',
    'hostname' => 'HP-PC',
]);

it('says when nothing matches the search', function (): void {
    inventoriedPc();

    Livewire::actingAs($this->user)->test(Index::class)
        ->set('search', 'Nothing Like This')
        ->assertSeeHtml('No devices match "Nothing Like This"');
});

it('sorts by RAM, biggest first, and flips on a second click', function (): void {
    inventoriedPc(['hostname' => 'ALPHA-PC'], ['total_ram_gb' => 8]);
    inventoriedPc(['hostname' => 'BRAVO-PC'], ['total_ram_gb' => 64]);
    inventoriedPc(['hostname' => 'CHARLIE-PC'], ['total_ram_gb' => 16]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSeeInOrder(['ALPHA-PC', 'BRAVO-PC', 'CHARLIE-PC'])
        ->call('sort', 'ram')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeInOrder(['BRAVO-PC', 'CHARLIE-PC', 'ALPHA-PC'])
        ->call('sort', 'ram')
        ->assertSeeInOrder(['ALPHA-PC', 'CHARLIE-PC', 'BRAVO-PC']);
});

it('refreshes when any device\'s system inventory syncs', function (): void {
    $page = Livewire::actingAs($this->user)->test(Index::class)->assertDontSee('NEW-PC');

    $device = inventoriedPc(['hostname' => 'NEW-PC']);

    $page->dispatch('echo-private:devices,SystemInventorySynced', ['deviceId' => $device->id])->assertSee('NEW-PC');

    expect(collect((new SystemInventorySynced($device->id))->broadcastOn())->map(fn (PrivateChannel $channel): string => $channel->name)->all())
        ->toContain('private-devices');
});

it('serves the page behind auth and the device policy, with Hardware after Software in the Fleet sidebar group', function (): void {
    inventoriedPc();

    $this->get(route('hardware.index'))->assertRedirect(route('login'));

    $this->actingAs($this->user)->get(route('hardware.index'))
        ->assertSuccessful()
        ->assertSeeInOrder(['Fleet', 'Devices', 'Pending', 'Software', 'Hardware', 'Automation'])
        ->assertSeeHtml('href="'.route('hardware.index').'" data-current');

    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'viewAny' ? false : null);
    $this->actingAs($this->user)->get(route('hardware.index'))->assertForbidden();
});

it('keeps a flat query count as the fleet grows', function (): void {
    $queriesFor = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->user)->test(Index::class);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    collect(range(1, 2))->each(fn () => inventoriedPc());
    $small = $queriesFor();

    collect(range(1, 10))->each(fn () => inventoriedPc());

    expect($queriesFor())->toBe($small);
});
