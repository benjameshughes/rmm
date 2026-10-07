<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Livewire\Software\Index;
use App\Livewire\Software\Show;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceSoftware;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
});

function pcWith(array $packages, array $deviceAttributes = []): Device
{
    $device = Device::factory()->active()->windows()->create(['agent_version' => '0.7.1', 'software_inventoried_at' => now(), ...$deviceAttributes]);

    collect($packages)->each(fn (array $package) => DeviceSoftware::factory()->create(['device_id' => $device->id, ...$package]));

    return $device;
}

function chrome(string $version, bool $outdated = false): array
{
    return ['package_id' => 'Google.Chrome', 'name' => 'Google Chrome', 'installed_version' => $version, 'latest_version' => $outdated ? '131.0' : null, 'is_update_available' => $outdated, 'source' => 'winget'];
}

function zip(): array
{
    return ['package_id' => '7zip.7zip', 'name' => '7-Zip', 'installed_version' => '24.08', 'source' => 'winget'];
}

it('rolls packages up across devices with their version spread, most devices behind first', function (): void {
    collect(range(1, 3))->each(fn () => pcWith([chrome('129.0', true), zip()]));
    pcWith([chrome('131.0'), zip(), ['package_id' => 'Old.Tool', 'name' => 'Old Tool', 'installed_version' => '1.0', 'latest_version' => '2.0', 'is_update_available' => true]]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertViewHas('packages', fn ($packages): bool => $packages->pluck('package_id')->all() === ['Google.Chrome', 'Old.Tool', '7zip.7zip']
            && (int) $packages->first()->device_count === 4
            && (int) $packages->first()->outdated_count === 3)
        ->assertSee('129.0 ×3, 131.0 ×1')
        ->assertSee('3 behind · 131.0')
        ->assertSee('Up to date')
        ->assertSeeHtml('href="'.route('software.show', ['id' => 'Google.Chrome']).'"');
});

it('leaves monitor-only and unapproved devices out of the fleet view', function (): void {
    pcWith([chrome('120.0', true)], ['is_monitor_only' => true]);
    pcWith([chrome('121.0', true)], ['status' => 'pending']);
    pcWith([zip()]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertViewHas('packages', fn ($packages): bool => $packages->pluck('package_id')->all() === ['7zip.7zip']);
});

it('searches packages by name or ID', function (): void {
    pcWith([chrome('131.0'), zip()]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->set('search', '7zip')
        ->assertSee('7-Zip')
        ->assertDontSee('Google Chrome');
});

it('explains the empty fleet view', function (): void {
    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSeeHtml('data-software-empty')
        ->assertSee('No inventory yet');
});

it('serves the software pages behind auth and the device policy, with Software in the Fleet sidebar group', function (): void {
    pcWith([chrome('131.0')]);

    $this->get(route('software.index'))->assertRedirect(route('login'));

    $this->actingAs($this->user)->get(route('software.index'))
        ->assertSuccessful()
        ->assertSeeInOrder(['Fleet', 'Devices', 'Pending', 'Software', 'Automation'])
        ->assertSeeHtml('href="'.route('software.index').'" data-current');

    $this->actingAs($this->user)->get(route('software.show', ['id' => 'Google.Chrome']))->assertSuccessful();
    $this->actingAs($this->user)->get(route('software.show', ['id' => 'Nobody.Has.This']))->assertSuccessful()->assertSee('No device has this app any more');

    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'viewAny' ? false : null);
    $this->actingAs($this->user)->get(route('software.index'))->assertForbidden();
});

it('shows each device\'s version of a package, outdated first, with Upgrade where winget can', function (): void {
    $behind = pcWith([chrome('129.0', true)], ['hostname' => 'BEHIND-PC']);
    $current = pcWith([chrome('131.0')], ['hostname' => 'CURRENT-PC']);

    Livewire::withQueryParams(['id' => 'Google.Chrome'])->actingAs($this->user)->test(Show::class)
        ->assertSee('Google Chrome')
        ->assertSeeInOrder(['BEHIND-PC', '129.0', 'Update available → 131.0', 'CURRENT-PC', '131.0'])
        ->assertSeeHtml('wire:click="upgrade('.$behind->software()->sole()->id.')"')
        ->assertDontSeeHtml('wire:click="upgrade('.$current->software()->sole()->id.')"')
        ->assertSee('Upgrade on all outdated (1)');
});

it('upgrades the package on one device', function (): void {
    $device = pcWith([chrome('129.0', true)]);

    Livewire::withQueryParams(['id' => 'Google.Chrome'])->actingAs($this->user)->test(Show::class)
        ->call('upgrade', $device->software()->sole()->id)
        ->assertDispatched('command-queued');

    expect($device->commands()->sole())
        ->script_id->toBe(Script::findSystem('winget-upgrade')->id)
        ->parameters->toBe(['PackageId' => 'Google.Chrome', 'CloseApp' => 'false']);
});

it('upgrades on every outdated device, skipping old agents and saying so', function (): void {
    $first = pcWith([chrome('129.0', true)]);
    $second = pcWith([chrome('130.0', true)]);
    $oldAgent = pcWith([chrome('128.0', true)], ['agent_version' => '0.6.1']);
    $current = pcWith([chrome('131.0')]);

    Livewire::withQueryParams(['id' => 'Google.Chrome'])->actingAs($this->user)->test(Show::class)
        ->call('upgradeAllOutdated')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => $params['slots']['heading'] === 'Upgrade queued on 2 of 3 devices'
            && $params['dataset']['variant'] === 'warning');

    expect(DeviceCommand::query()->pluck('device_id')->sort()->values()->all())->toBe([$first->id, $second->id])
        ->and(DeviceCommand::query()->get()->every(fn (DeviceCommand $command): bool => $command->parameters === ['PackageId' => 'Google.Chrome', 'CloseApp' => 'false']))->toBeTrue()
        ->and($oldAgent->commands()->count() + $current->commands()->count())->toBe(0);
});

it('refuses upgrades to users who may not run commands', function (): void {
    $device = pcWith([chrome('129.0', true)]);
    $page = Livewire::withQueryParams(['id' => 'Google.Chrome'])->actingAs($this->user)->test(Show::class);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'runCommands' ? false : null);

    $page->call('upgradeAllOutdated')->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('refreshes both pages when any device\'s inventory syncs', function (): void {
    $device = pcWith([chrome('129.0', true)]);

    $index = Livewire::actingAs($this->user)->test(Index::class)->assertDontSee('7-Zip');
    $show = Livewire::withQueryParams(['id' => 'Google.Chrome'])->actingAs($this->user)->test(Show::class)->assertSee('129.0');

    DeviceSoftware::factory()->create(['device_id' => $device->id, ...zip()]);
    $device->software()->where('package_id', 'Google.Chrome')->update(['installed_version' => '131.0', 'is_update_available' => false]);

    $index->dispatch('echo-private:devices,SoftwareInventorySynced', ['deviceId' => $device->id, 'packageCount' => 2])->assertSee('7-Zip');
    $show->dispatch('echo-private:devices,SoftwareInventorySynced', ['deviceId' => $device->id, 'packageCount' => 2])
        ->assertDontSee('Update available')
        ->assertDontSee('Upgrade on all outdated');
});

it('keeps both pages at a flat query count as the fleet grows', function (): void {
    $queriesFor = function (Closure $render): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $render();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $index = fn () => Livewire::actingAs($this->user)->test(Index::class);
    $show = fn () => Livewire::withQueryParams(['id' => 'Google.Chrome'])->actingAs($this->user)->test(Show::class);

    collect(range(1, 2))->each(fn () => pcWith([chrome('129.0', true), zip()]));
    $smallIndex = $queriesFor($index);
    $smallShow = $queriesFor($show);

    collect(range(1, 10))->each(fn (int $index) => pcWith([chrome("12{$index}.0", true), zip(), ['package_id' => "Extra.{$index}", 'name' => "Extra {$index}"]]));

    expect($queriesFor($index))->toBe($smallIndex)
        ->and($queriesFor($show))->toBe($smallShow);
});

it('says a package is not in winget instead of claiming it is up to date', function (): void {
    pcWith([['package_id' => 'Dell.CommandUpdate', 'name' => 'Dell Command | Update', 'installed_version' => '5.2.0', 'latest_version' => null, 'is_update_available' => false, 'source' => null]]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee('Not in winget')
        ->assertDontSee('Up to date');
});

describe('fleet install and uninstall', function (): void {
    it('installs a winget app only on inventoried Windows devices that do not have it', function (): void {
        $has = pcWith([zip()]);
        $missingOne = pcWith([chrome('130.0')]);
        $missingTwo = pcWith([]);
        $notInventoried = pcWith([], ['software_inventoried_at' => null]);
        $server = Device::factory()->active()->monitorOnly()->create(['software_inventoried_at' => now()]);

        Livewire::actingAs($this->user)->test(Show::class, ['packageId' => '7zip.7zip'])
            ->assertSee('Install where missing (2)')
            ->call('installEverywhere')
            ->assertDispatched('command-queued');

        $commands = DeviceCommand::query()->with('script')->get();
        expect($commands->pluck('device_id')->sort()->values()->all())->toBe(collect([$missingOne->id, $missingTwo->id])->sort()->values()->all())
            ->and($commands->pluck('script.slug')->unique()->all())->toBe(['winget-install'])
            ->and($commands->first()->parameters)->toBe(['PackageId' => '7zip.7zip']);
    });

    it('offers no install for apps winget does not know', function (): void {
        pcWith([['package_id' => 'ARP\Machine\X64\Thing', 'name' => 'Thing', 'installed_version' => '1.0', 'source' => null]]);
        pcWith([]);

        Livewire::actingAs($this->user)->test(Show::class, ['packageId' => 'ARP\Machine\X64\Thing'])
            ->assertDontSeeHtml('data-install-everywhere')
            ->call('installEverywhere')
            ->assertNotFound();
    });

    it('uninstalls from every device that has it and nothing else', function (): void {
        $first = pcWith([zip()]);
        $second = pcWith([zip(), chrome('130.0')]);
        pcWith([chrome('130.0')]);

        Livewire::actingAs($this->user)->test(Show::class, ['packageId' => '7zip.7zip'])
            ->assertSee('Uninstall everywhere (2)')
            ->call('uninstallEverywhere');

        $commands = DeviceCommand::query()->with('script')->get();
        expect($commands->pluck('device_id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all())
            ->and($commands->pluck('script.slug')->unique()->all())->toBe(['winget-uninstall']);
    });
});

it('says the app is gone instead of erroring once the last install disappears', function (): void {
    $device = pcWith([zip()]);
    $page = Livewire::actingAs($this->user)->test(Show::class, ['packageId' => '7zip.7zip'])->assertSee('7-Zip');

    $device->software()->delete();

    $page->dispatch('echo-private:devices,SoftwareInventorySynced', [])
        ->assertSuccessful()
        ->assertSeeHtml('data-software-removed')
        ->assertSee('7zip.7zip');
});

describe('package command status', function (): void {
    it('swaps a row\'s buttons for the in-flight command on the package page until it finishes', function (): void {
        $device = pcWith([chrome('129.0', true)]);
        $page = Livewire::actingAs($this->user)->test(Show::class, ['packageId' => 'Google.Chrome'])
            ->call('upgrade', $device->software()->sole()->id);

        $command = DeviceCommand::sole();
        $page->assertSee('Queued: Upgrade')->assertSeeHtml("commandId: {$command->id}");

        $command->markAsSent();
        $command->markAsRunning();
        $page->dispatch('echo-private:devices,CommandUpdated', [])->assertSee('Upgrading...');

        $command->markAsCompleted('OK', 0);
        $page->dispatch('echo-private:devices,CommandUpdated', [])->assertDontSeeHtml('data-run-button-busy');
    });

    it('shows the in-flight uninstall on the device apps tab, only on that app\'s row', function (): void {
        $device = pcWith([zip(), chrome('130.0')]);

        Livewire::actingAs($this->user)->test(App\Livewire\Devices\Apps::class, ['device' => $device])
            ->call('uninstallPackage', $device->software()->where('package_id', '7zip.7zip')->sole()->id)
            ->assertSee('Queued: Uninstall')
            ->assertSee('Uninstall');
    });
});

describe('closing the app first', function (): void {
    it('passes Close the app first through to uninstall everywhere and per-row upgrades, off by default', function (): void {
        $device = pcWith([chrome('129.0', true)]);

        Livewire::actingAs($this->user)->test(Show::class, ['packageId' => 'Google.Chrome'])
            ->assertSeeHtml('data-close-app-first')
            ->call('upgrade', $device->software()->sole()->id);

        expect(DeviceCommand::sole()->parameters)->toBe(['PackageId' => 'Google.Chrome', 'CloseApp' => 'false']);
        DeviceCommand::query()->delete();

        Livewire::actingAs($this->user)->test(Show::class, ['packageId' => 'Google.Chrome'])
            ->set('closeAppFirst', true)
            ->call('uninstallEverywhere');

        expect(DeviceCommand::sole()->parameters)->toBe(['PackageId' => 'Google.Chrome', 'CloseApp' => 'true']);
    });

    it('still installs with only a package ID, since install has no app to close', function (): void {
        pcWith([zip()]);
        pcWith([]);

        Livewire::actingAs($this->user)->test(Show::class, ['packageId' => '7zip.7zip'])
            ->set('closeAppFirst', true)
            ->call('installEverywhere')
            ->assertHasNoErrors();

        expect(DeviceCommand::sole()->parameters)->toBe(['PackageId' => '7zip.7zip']);
    });

    it('passes it from the device apps tab too', function (): void {
        $device = pcWith([zip()]);

        Livewire::actingAs($this->user)->test(App\Livewire\Devices\Apps::class, ['device' => $device])
            ->set('closeAppFirst', true)
            ->call('uninstallPackage', $device->software()->sole()->id);

        expect(DeviceCommand::sole()->parameters)->toBe(['PackageId' => '7zip.7zip', 'CloseApp' => 'true']);
    });
});

it('hides the Microsoft runtimes other apps depend on, but not Microsoft apps', function (array $package): void {
    pcWith([chrome('131.0'), $package, ['package_id' => 'Microsoft.Edge', 'name' => 'Microsoft Edge', 'source' => 'winget']]);

    expect(app(App\Queries\SoftwareQueries::class)->fleetPackages()->pluck('package_id')->all())
        ->toContain('Google.Chrome', 'Microsoft.Edge')
        ->not->toContain($package['package_id']);
})->with([
    'desktop runtime' => [['package_id' => 'Microsoft.DotNet.DesktopRuntime.8', 'name' => 'Microsoft .NET Windows Desktop Runtime 8.0']],
    'vc redist' => [['package_id' => 'Microsoft.VCRedist.2015+.x64', 'name' => 'Microsoft Visual C++ v14 Redistributable (x64)']],
    'msix native framework' => [['package_id' => 'MSIX\Microsoft.NET.Native.Framework.1.7_1.7.25531.0_x64__8wekyb3d8bbwe', 'name' => 'Microsoft.NET.Native.Framework.1.7']],
    'arp vc redist by name' => [['package_id' => 'ARP\Machine\X86\{9A25302D-30C0-39D9-BD6F-21E6EC160475}', 'name' => 'Microsoft Visual C++ 2008 Redistributable - x86 9.0.30729.17']],
    'ui xaml' => [['package_id' => 'Microsoft.UI.Xaml.2.8', 'name' => 'Microsoft.UI.Xaml']],
]);

it('hides the runtimes on a device page too', function (): void {
    $device = pcWith([chrome('131.0'), ['package_id' => 'Microsoft.WindowsAppRuntime.1.8', 'name' => 'Windows App Runtime 1.8']]);

    expect(app(App\Queries\SoftwareQueries::class)->forDevice($device)->pluck('package_id')->all())->toBe(['Google.Chrome']);
});
