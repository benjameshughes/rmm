<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Livewire\Devices\Apps;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceSoftware;
use App\Models\Script;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['hostname' => 'OFFICE-PC', 'agent_version' => '0.7.1', 'software_inventoried_at' => now()->subHours(2)]);
});

function installed(Device $device, array $attributes = []): DeviceSoftware
{
    return DeviceSoftware::factory()->create(['device_id' => $device->id, ...$attributes]);
}

it('lists installed software with versions, updates and sources, updates first', function (): void {
    installed($this->device, ['package_id' => '7zip.7zip', 'name' => '7-Zip', 'installed_version' => '23.01']);
    installed($this->device, ['package_id' => 'Mozilla.Firefox', 'name' => 'Mozilla Firefox', 'installed_version' => '129.0', 'latest_version' => '131.0', 'is_update_available' => true]);
    installed($this->device, ['package_id' => 'ARP\\Machine\\X64\\Tool', 'name' => 'Old Tool', 'source' => null]);

    Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device])
        ->assertSee('Installed software')
        ->assertSee('Last checked 2 hours ago')
        ->assertSeeInOrder(['Mozilla Firefox', '129.0', 'Update available → 131.0', '7-Zip', '23.01'])
        ->assertSee('Not from winget')
        ->assertSee('Refresh inventory');
});

it('searches by name or package ID', function (): void {
    installed($this->device, ['package_id' => '7zip.7zip', 'name' => '7-Zip']);
    installed($this->device, ['package_id' => 'Mozilla.Firefox', 'name' => 'Mozilla Firefox']);

    Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device])
        ->set('softwareSearch', 'mozilla')
        ->assertSee('Mozilla Firefox')
        ->assertDontSee('7-Zip');
});

it('explains the missing inventory and runs it on request', function (): void {
    $this->device->forceFill(['software_inventoried_at' => null])->save();

    Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device])
        ->assertSeeHtml('data-software-empty')
        ->assertSee('Run now')
        ->call('refreshInventory')
        ->assertDispatched('command-queued')
        ->assertSee('Queued...')
        ->assertSeeHtml('data-run-button-busy');

    expect($this->device->commands()->sole()->script_id)->toBe(Script::findSystem('winget-inventory')->id);
});

it('does not queue a second inventory while one is waiting', function (): void {
    $apps = Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device]);

    $apps->call('refreshInventory');
    $apps->call('refreshInventory')->assertSee('Queued...');

    expect($this->device->commands()->count())->toBe(1);
});

it('upgrades a winget package and uninstalls any package with its ID as the parameter', function (string $method, string $slug, array $attributes): void {
    $package = installed($this->device, ['package_id' => 'Mozilla.Firefox', ...$attributes]);

    Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device])
        ->call($method, $package->id)
        ->assertHasNoErrors()
        ->assertDispatched('command-queued');

    expect($this->device->commands()->sole())
        ->script_id->toBe(Script::findSystem($slug)->id)
        ->parameters->toBe(['PackageId' => 'Mozilla.Firefox'])
        ->status->toBe(CommandStatus::Pending);
})->with([
    'upgrade' => ['upgradePackage', 'winget-upgrade', ['is_update_available' => true, 'latest_version' => '131.0']],
    'uninstall' => ['uninstallPackage', 'winget-uninstall', []],
]);

it('only offers Upgrade for winget packages with an update, and refuses it otherwise', function (array $attributes, bool $isUpgradable): void {
    $package = installed($this->device, $attributes);

    $apps = Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device])
        ->assertSeeHtml("\$wire.uninstallPackage({$package->id})")
        ->assertSee('from OFFICE-PC?');

    $isUpgradable
        ? $apps->assertSeeHtml("wire:click=\"upgradePackage({$package->id})\"")
        : $apps->assertDontSeeHtml("wire:click=\"upgradePackage({$package->id})\"")->call('upgradePackage', $package->id)->assertStatus(422);
})->with([
    'winget update' => [['is_update_available' => true, 'source' => 'winget'], true],
    'current' => [['is_update_available' => false, 'source' => 'winget'], false],
    'ARP with update flag' => [['is_update_available' => true, 'source' => null], false],
]);

it('only changes packages from this device\'s own inventory', function (): void {
    $elsewhere = installed(Device::factory()->active()->windows()->create());

    $apps = Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device]);

    expect(fn () => $apps->call('uninstallPackage', $elsewhere->id))->toThrow(ModelNotFoundException::class)
        ->and(DeviceCommand::query()->count())->toBe(0);
});

it('surfaces the agent version gate instead of queueing', function (): void {
    $this->device->forceFill(['agent_version' => '0.6.1'])->save();
    $package = installed($this->device);

    Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device])
        ->call('uninstallPackage', $package->id)
        ->assertHasErrors(['script'])
        ->assertSee('Scripts with parameters need agent');

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('hides every software action from users who may not run commands and refuses them', function (): void {
    $package = installed($this->device, ['is_update_available' => true]);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'runCommands' ? false : null);

    Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device])
        ->assertDontSee('Refresh inventory')
        ->assertDontSeeHtml('upgradePackage(')
        ->assertDontSeeHtml('uninstallPackage(')
        ->call('uninstallPackage', $package->id)->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('hides installed software on monitor-only and linux devices', function (string $state): void {
    $device = Device::factory()->active()->{$state}()->create();

    Livewire::actingAs($this->user)->test(Apps::class, ['device' => $device])
        ->assertDontSee('Installed software')
        ->call('refreshInventory');

    expect(DeviceCommand::query()->count())->toBe(0);
})->with(['monitorOnly', 'linux']);

it('refreshes the list when the device\'s inventory syncs', function (): void {
    $apps = Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device])
        ->assertDontSee('VLC media player');

    installed($this->device, ['package_id' => 'VideoLAN.VLC', 'name' => 'VLC media player']);

    $apps->dispatch("echo-private:devices.{$this->device->id},SoftwareInventorySynced", ['deviceId' => $this->device->id, 'packageCount' => 1])
        ->assertSee('VLC media player');
});

it('keeps the apps tab query count flat however much software is installed', function (): void {
    $queriesFor = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device]);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    collect(range(1, 2))->each(fn (int $index) => installed($this->device, ['package_id' => "Few.{$index}"]));
    $few = $queriesFor();

    collect(range(1, 20))->each(fn (int $index) => installed($this->device, ['package_id' => "Many.{$index}", 'is_update_available' => true]));
    $many = $queriesFor();

    expect($many)->toBe($few);
});
