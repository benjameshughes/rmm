<?php

declare(strict_types=1);

use App\Actions\Software\SyncDeviceSoftware;
use App\Events\SoftwareInventorySynced;
use App\Models\Device;
use App\Models\DeviceSoftware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->device = Device::factory()->active()->windows()->create();
});

/**
 * @param  array<int, array<string, mixed>>  $packages
 */
function inventoryOutput(array $packages): string
{
    return json_encode($packages)."\n";
}

function firefox(array $overrides = []): array
{
    return ['id' => 'Mozilla.Firefox', 'name' => 'Mozilla Firefox', 'installed_version' => '129.0', 'latest_version' => '131.0', 'is_update_available' => true, 'source' => 'winget', ...$overrides];
}

it('stores every package from the inventory, including ARP entries with no source', function (): void {
    $synced = app(SyncDeviceSoftware::class)($this->device, inventoryOutput([
        firefox(),
        ['id' => 'ARP\\Machine\\X64\\{6F1C1A2B-0000-4000-8000-00000000ABCD}', 'name' => 'Line of Business App', 'installed_version' => '2.1', 'latest_version' => null, 'is_update_available' => false, 'source' => null],
    ]));

    expect($synced)->toBeTrue()
        ->and($this->device->software()->orderBy('name')->get()->map->only(['package_id', 'installed_version', 'latest_version', 'is_update_available', 'source'])->all())->toBe([
            ['package_id' => 'ARP\\Machine\\X64\\{6F1C1A2B-0000-4000-8000-00000000ABCD}', 'installed_version' => '2.1', 'latest_version' => null, 'is_update_available' => false, 'source' => null],
            ['package_id' => 'Mozilla.Firefox', 'installed_version' => '129.0', 'latest_version' => '131.0', 'is_update_available' => true, 'source' => 'winget'],
        ])
        ->and($this->device->fresh()->software_inventoried_at)->not->toBeNull();
});

it('updates packages it already knows and removes the ones that are gone', function (): void {
    $kept = DeviceSoftware::factory()->create(['device_id' => $this->device->id, 'package_id' => 'Mozilla.Firefox', 'installed_version' => '128.0']);
    DeviceSoftware::factory()->create(['device_id' => $this->device->id, 'package_id' => 'VideoLAN.VLC']);
    $otherDevice = DeviceSoftware::factory()->create(['package_id' => 'VideoLAN.VLC']);

    app(SyncDeviceSoftware::class)($this->device, inventoryOutput([firefox(['installed_version' => '131.0', 'latest_version' => null, 'is_update_available' => false])]));

    expect($this->device->software()->sole())
        ->id->toBe($kept->id)
        ->installed_version->toBe('131.0')
        ->is_update_available->toBeFalse()
        ->and($otherDevice->fresh())->not->toBeNull();
});

it('empties the inventory when winget reports nothing installed', function (): void {
    DeviceSoftware::factory()->count(2)->sequence(['package_id' => 'A.A'], ['package_id' => 'B.B'])->create(['device_id' => $this->device->id]);

    expect(app(SyncDeviceSoftware::class)($this->device, "[]\n"))->toBeTrue()
        ->and($this->device->software()->count())->toBe(0);
});

it('leaves the inventory untouched and logs when the output is not the JSON array', function (string $output): void {
    Log::spy();
    DeviceSoftware::factory()->create(['device_id' => $this->device->id, 'package_id' => 'Mozilla.Firefox']);

    expect(app(SyncDeviceSoftware::class)($this->device, $output))->toBeFalse()
        ->and($this->device->software()->count())->toBe(1)
        ->and($this->device->fresh()->software_inventoried_at)->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'software.inventory_unparseable')->once();
})->with([
    'nothing' => [''],
    'an error' => ["ATTENTION: winget is not installed and could not be installed\n"],
    'truncated JSON' => ['[{"id":"Mozilla.Firefox","name":"Moz'],
    'an object' => ['{"id":"Mozilla.Firefox"}'],
]);

it('finds the JSON line among other output and ignores stderr', function (): void {
    $output = "Some progress text\n".json_encode([firefox()]).config('commands.stderr_separator').'[{"id":"Not.Real"}]';

    app(SyncDeviceSoftware::class)($this->device, $output);

    expect($this->device->software()->pluck('package_id')->all())->toBe(['Mozilla.Firefox']);
});

it('skips entries without an ID, names nameless ones after their ID and keeps one row per ID', function (): void {
    app(SyncDeviceSoftware::class)($this->device, inventoryOutput([
        ['name' => 'No ID'],
        'not an object',
        ['id' => 'Nameless.App', 'name' => ''],
        firefox(['installed_version' => '128.0']),
        firefox(['installed_version' => '129.0']),
    ]));

    expect($this->device->software()->orderBy('package_id')->get()->map->only(['package_id', 'name', 'installed_version'])->all())->toBe([
        ['package_id' => 'Mozilla.Firefox', 'name' => 'Mozilla Firefox', 'installed_version' => '129.0'],
        ['package_id' => 'Nameless.App', 'name' => 'Nameless.App', 'installed_version' => null],
    ]);
});

it('never stores an inventory for a monitor-only device', function (): void {
    $server = Device::factory()->monitorOnly()->active()->create();

    expect(app(SyncDeviceSoftware::class)($server, inventoryOutput([firefox()])))->toBeFalse()
        ->and(DeviceSoftware::query()->count())->toBe(0);
});

it('announces the sync with scalars only', function (): void {
    Event::fake([SoftwareInventorySynced::class]);

    app(SyncDeviceSoftware::class)($this->device, inventoryOutput([firefox()]));

    Event::assertDispatched(SoftwareInventorySynced::class, fn (SoftwareInventorySynced $event): bool => $event->deviceId === $this->device->id
        && $event->packageCount === 1
        && $event->broadcastWith() === ['deviceId' => $this->device->id, 'packageCount' => 1]);
});

it('fits a large inventory in the command output column', function (): void {
    $packages = collect(range(1, 400))->map(fn (int $index): array => firefox(['id' => "Vendor{$index}.LongishApplicationName{$index}", 'name' => "Vendor {$index} Longish Application Name"]))->all();
    $output = inventoryOutput($packages);

    expect(strlen($output))->toBeGreaterThan(65535)
        ->and(app(SyncDeviceSoftware::class)($this->device, $output))->toBeTrue()
        ->and($this->device->software()->count())->toBe(400);
});
