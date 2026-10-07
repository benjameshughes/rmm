<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Livewire\Devices\Apps;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceSoftware;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['agent_version' => '0.7.1', 'software_inventoried_at' => now()]);
    $this->firefox = DeviceSoftware::factory()->outdated('131.0')->create(['device_id' => $this->device->id, 'package_id' => 'Mozilla.Firefox', 'name' => 'Mozilla Firefox']);
    $this->zip = DeviceSoftware::factory()->create(['device_id' => $this->device->id, 'package_id' => '7zip.7zip', 'name' => '7-Zip']);
    $this->tool = DeviceSoftware::factory()->fromArp()->outdated()->create(['device_id' => $this->device->id, 'name' => 'Old Tool']);
    $this->msix = DeviceSoftware::factory()->create(['device_id' => $this->device->id, 'package_id' => 'MSIX\\Contoso.App_1.0_x64__abc', 'name' => 'Contoso App', 'source' => null]);
});

function deviceApps(Device $device)
{
    return Livewire::actingAs(test()->user)->test(Apps::class, ['device' => $device]);
}

function appIds($component): array
{
    return $component->viewData('software')->pluck('package_id')->all();
}

it('filters the device\'s apps by source and updates available', function (): void {
    expect(appIds(deviceApps($this->device)->set('source', 'winget')))->toBe(['Mozilla.Firefox', '7zip.7zip'])
        ->and(appIds(deviceApps($this->device)->set('source', 'arp')))->toBe([$this->tool->package_id])
        ->and(appIds(deviceApps($this->device)->set('source', 'msix')))->toBe(['MSIX\\Contoso.App_1.0_x64__abc'])
        ->and(appIds(deviceApps($this->device)->set('isOutdatedOnly', true)))->toBe(['Mozilla.Firefox', $this->tool->package_id]);
});

it('ticks every app on the page and shows the bulk bar', function (): void {
    deviceApps($this->device)
        ->assertSeeHtml('wire:model="selectedSoftware"')
        ->assertSeeHtml('data-bulk-bar')
        ->set('selectAll', true)
        ->assertSet('selectedSoftware', collect([$this->firefox, $this->tool, $this->zip, $this->msix])->sortBy('name')->sortByDesc('is_update_available')->pluck('id')->map(fn (int $id): string => (string) $id)->values()->all())
        ->set('source', 'arp')
        ->assertSet('selectedSoftware', []);
});

it('upgrades the selected apps winget can upgrade and says how many it skipped', function (): void {
    deviceApps($this->device)
        ->set('selectedSoftware', [(string) $this->firefox->id, (string) $this->tool->id, (string) $this->zip->id])
        ->call('planPackageCommands', 'winget-upgrade')
        ->assertSee('Upgrade Mozilla.Firefox: 1 command across 1 device.')
        ->assertSee('1 outdated install skipped: winget did not install it, so it cannot upgrade it by ID.')
        ->set('closeAppFirst', true)
        ->call('queuePlannedPackageCommands')
        ->assertDispatched('command-queued')
        ->assertSet('selectedSoftware', []);

    expect(DeviceCommand::sole())
        ->script_id->toBe(Script::findSystem('winget-upgrade')->id)
        ->parameters->toBe(['PackageId' => 'Mozilla.Firefox', 'CloseApp' => 'true']);
});

it('uninstalls the selected apps, whatever their source, and shows each in flight on its row', function (): void {
    $page = deviceApps($this->device)
        ->set('selectedSoftware', [(string) $this->zip->id, (string) $this->tool->id])
        ->call('planPackageCommands', 'winget-uninstall')
        ->assertSee('Uninstall 2 apps: 2 commands across 1 device.')
        ->call('queuePlannedPackageCommands');

    expect(DeviceCommand::query()->get()->pluck('parameters.PackageId')->all())->toEqualCanonicalizing(['7zip.7zip', $this->tool->package_id]);

    $page->assertSeeHtml('data-run-button-busy')->assertSee('Queued: Uninstall');
});

it('only reaches apps in this device\'s own inventory', function (): void {
    $other = Device::factory()->active()->windows()->create(['agent_version' => '0.7.1', 'software_inventoried_at' => now()]);
    $othersApp = DeviceSoftware::factory()->create(['device_id' => $other->id, 'package_id' => 'VideoLAN.VLC']);

    deviceApps($this->device)
        ->set('selectedSoftware', [(string) $othersApp->id])
        ->call('planPackageCommands', 'winget-uninstall')
        ->assertSee('Nothing to uninstall.')
        ->call('queuePlannedPackageCommands')
        ->assertStatus(422);

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('refuses bulk actions to users who may not run commands, and hides the checkboxes', function (): void {
    $page = deviceApps($this->device)
        ->set('selectedSoftware', [(string) $this->zip->id])
        ->call('planPackageCommands', 'winget-uninstall');
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'runCommands' ? false : null);

    $page->call('queuePlannedPackageCommands')->assertForbidden();

    deviceApps($this->device)
        ->assertDontSeeHtml('wire:model="selectedSoftware"')
        ->assertDontSeeHtml('data-bulk-bar');

    expect(DeviceCommand::query()->count())->toBe(0);
});
