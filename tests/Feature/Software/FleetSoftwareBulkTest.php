<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Livewire\Software\Index;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\DeviceSoftware;
use App\Models\Script;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
});

function fleetPc(array $packages, array $deviceAttributes = []): Device
{
    $device = Device::factory()->active()->windows()->create(['agent_version' => '0.7.1', 'software_inventoried_at' => now(), ...$deviceAttributes]);

    collect($packages)->each(fn (array $package) => DeviceSoftware::factory()->create(['device_id' => $device->id, ...$package]));

    return $device;
}

function bulkFirefox(bool $outdated = false): array
{
    return ['package_id' => 'Mozilla.Firefox', 'name' => 'Mozilla Firefox', 'installed_version' => '129.0', 'latest_version' => $outdated ? '131.0' : null, 'is_update_available' => $outdated, 'source' => 'winget'];
}

function bulkVlc(bool $outdated = false): array
{
    return ['package_id' => 'VideoLAN.VLC', 'name' => 'VLC media player', 'installed_version' => '3.0.20', 'latest_version' => $outdated ? '3.0.21' : null, 'is_update_available' => $outdated, 'source' => 'winget'];
}

function arpTool(bool $outdated = false): array
{
    return ['package_id' => 'ARP\\Machine\\X64\\Tool', 'name' => 'Old Tool', 'installed_version' => '1.0', 'latest_version' => $outdated ? '2.0' : null, 'is_update_available' => $outdated, 'source' => null];
}

function msixApp(): array
{
    return ['package_id' => 'MSIX\\Contoso.App_1.0.0.0_x64__abc', 'name' => 'Contoso App', 'installed_version' => '1.0', 'source' => null];
}

function listedPackages($component): array
{
    return $component->viewData('packages')->pluck('package_id')->all();
}

describe('filters', function (): void {
    it('filters by source', function (string $source, array $expected): void {
        fleetPc([bulkFirefox(), arpTool(), msixApp()]);

        $page = Livewire::actingAs($this->user)->test(Index::class)->set('source', $source);

        expect(listedPackages($page))->toBe($expected);
    })->with([
        'winget' => ['winget', ['Mozilla.Firefox']],
        'arp' => ['arp', ['ARP\\Machine\\X64\\Tool']],
        'msix' => ['msix', ['MSIX\\Contoso.App_1.0.0.0_x64__abc']],
    ]);

    it('shows only packages with an update when asked', function (): void {
        fleetPc([bulkFirefox(outdated: true), bulkVlc()]);
        fleetPc([bulkFirefox(), bulkVlc()]);

        $page = Livewire::actingAs($this->user)->test(Index::class)->set('isOutdatedOnly', true);

        expect(listedPackages($page))->toBe(['Mozilla.Firefox'])
            ->and((int) $page->viewData('packages')->first()->device_count)->toBe(2);
    });

    it('filters to packages on every device or only some', function (): void {
        fleetPc([bulkFirefox(), bulkVlc()]);
        fleetPc([bulkFirefox()]);

        expect(listedPackages(Livewire::actingAs($this->user)->test(Index::class)->set('coverage', 'every')))->toBe(['Mozilla.Firefox'])
            ->and(listedPackages(Livewire::actingAs($this->user)->test(Index::class)->set('coverage', 'some')))->toBe(['VideoLAN.VLC']);
    });

    it('counts only installs on devices in the chosen group or tag', function (): void {
        $group = DeviceGroup::factory()->create();
        $tag = Tag::factory()->create();
        fleetPc([bulkFirefox(outdated: true), bulkVlc()], ['device_group_id' => $group->id]);
        fleetPc([bulkFirefox(outdated: true)])->tags()->attach($tag);
        fleetPc([bulkFirefox()]);

        $grouped = Livewire::actingAs($this->user)->test(Index::class)->set('groupFilter', (string) $group->id);
        $tagged = Livewire::actingAs($this->user)->test(Index::class)->set('tagFilter', (string) $tag->id);

        expect(listedPackages($grouped))->toBe(['Mozilla.Firefox', 'VideoLAN.VLC'])
            ->and((int) $grouped->viewData('packages')->first()->device_count)->toBe(1)
            ->and($grouped->viewData('inventoriedDevices'))->toBe(1)
            ->and(listedPackages($tagged))->toBe(['Mozilla.Firefox'])
            ->and((int) $tagged->viewData('packages')->first()->outdated_count)->toBe(1);
    });

    it('searches and keeps filters and sort in the query string', function (): void {
        fleetPc([bulkFirefox(), bulkVlc()]);

        Livewire::withQueryParams(['q' => 'vlc', 'source' => 'winget', 'updates' => '0', 'sort' => 'name', 'direction' => 'asc'])
            ->actingAs($this->user)->test(Index::class)
            ->assertSet('search', 'vlc')
            ->assertSet('source', 'winget')
            ->assertSet('sortBy', 'name')
            ->assertSee('VLC media player')
            ->assertDontSee('Mozilla Firefox');
    });

    it('ignores hand-edited filter and sort values', function (): void {
        fleetPc([bulkFirefox(), bulkVlc()]);

        $page = Livewire::withQueryParams(['source' => 'nope', 'coverage' => 'nope', 'group' => 'x', 'sort' => 'nope', 'direction' => 'sideways'])
            ->actingAs($this->user)->test(Index::class)
            ->assertSuccessful();

        expect(listedPackages($page))->toHaveCount(2);
    });

    it('clears every filter', function (): void {
        Livewire::actingAs($this->user)->test(Index::class)
            ->set('search', 'x')->set('source', 'arp')->set('isOutdatedOnly', true)->set('coverage', 'some')
            ->call('clearFilters')
            ->assertSet('search', '')->assertSet('source', '')->assertSet('isOutdatedOnly', false)->assertSet('coverage', '');
    });
});

describe('sorting', function (): void {
    it('sorts by each column and flips direction on a second click', function (): void {
        fleetPc([bulkFirefox(), bulkVlc(outdated: true)]);
        fleetPc([bulkFirefox()]);

        $page = Livewire::actingAs($this->user)->test(Index::class);
        expect(listedPackages($page))->toBe(['VideoLAN.VLC', 'Mozilla.Firefox']);

        $page->call('sort', 'devices');
        expect(listedPackages($page))->toBe(['Mozilla.Firefox', 'VideoLAN.VLC'])
            ->and($page->get('sortDirection'))->toBe('desc');

        $page->call('sort', 'name');
        expect(listedPackages($page))->toBe(['Mozilla.Firefox', 'VideoLAN.VLC']);

        $page->call('sort', 'name');
        expect(listedPackages($page))->toBe(['VideoLAN.VLC', 'Mozilla.Firefox'])
            ->and($page->get('sortDirection'))->toBe('desc');
    });
});

describe('selection', function (): void {
    it('ticks every package on the page, shows the bulk bar and clears', function (): void {
        fleetPc([bulkFirefox(), bulkVlc()]);

        Livewire::actingAs($this->user)->test(Index::class)
            ->assertSeeHtml('wire:model="selectedPackages"')
            ->assertSeeHtml('data-bulk-bar')
            ->assertSeeHtml('data-bulk-upgrade')
            ->assertSeeHtml('data-bulk-uninstall')
            ->set('selectAll', true)
            ->assertSet('selectedPackages', ['Mozilla.Firefox', 'VideoLAN.VLC'])
            ->call('clearSelection')
            ->assertSet('selectedPackages', [])
            ->assertSet('selectAll', false);
    });

    it('drops the ticks when a filter changes', function (): void {
        Livewire::actingAs($this->user)->test(Index::class)
            ->set('selectedPackages', ['Mozilla.Firefox'])
            ->set('source', 'arp')
            ->assertSet('selectedPackages', []);
    });
});

describe('bulk upgrade', function (): void {
    it('upgrades only outdated winget installs and says how many it skipped', function (): void {
        $outdated = fleetPc([bulkFirefox(outdated: true), arpTool(outdated: true)]);
        $current = fleetPc([bulkFirefox(), bulkVlc(outdated: true)]);
        fleetPc([arpTool(outdated: true)]);

        Livewire::actingAs($this->user)->test(Index::class)
            ->set('selectedPackages', ['Mozilla.Firefox', 'ARP\\Machine\\X64\\Tool', 'VideoLAN.VLC'])
            ->call('planPackageCommands', 'winget-upgrade')
            ->assertSet('showPackageCommandModal', true)
            ->assertSee('Upgrade 2 apps: 2 commands across 2 devices.')
            ->assertSee('2 outdated installs skipped: winget did not install them, so it cannot upgrade them by ID.')
            ->call('queuePlannedPackageCommands')
            ->assertDispatched('command-queued')
            ->assertSet('selectedPackages', [])
            ->assertSet('showPackageCommandModal', false);

        $commands = DeviceCommand::query()->get();
        expect($commands)->toHaveCount(2)
            ->and($commands->every(fn (DeviceCommand $command): bool => $command->script_id === Script::findSystem('winget-upgrade')->id))->toBeTrue()
            ->and($commands->mapWithKeys(fn (DeviceCommand $command): array => [$command->device_id => $command->parameters['PackageId']])->all())
            ->toBe([$outdated->id => 'Mozilla.Firefox', $current->id => 'VideoLAN.VLC']);
    });

    it('passes Close the app first through', function (): void {
        fleetPc([bulkFirefox(outdated: true)]);

        Livewire::actingAs($this->user)->test(Index::class)
            ->set('selectedPackages', ['Mozilla.Firefox'])
            ->call('planPackageCommands', 'winget-upgrade')
            ->assertSeeHtml('data-close-app-first')
            ->set('closeAppFirst', true)
            ->call('queuePlannedPackageCommands');

        expect(DeviceCommand::sole()->parameters)->toBe(['PackageId' => 'Mozilla.Firefox', 'CloseApp' => 'true']);
    });

    it('offers nothing to confirm when no selected install can be upgraded', function (): void {
        fleetPc([arpTool(outdated: true), bulkVlc()]);

        Livewire::actingAs($this->user)->test(Index::class)
            ->set('selectedPackages', ['ARP\\Machine\\X64\\Tool', 'VideoLAN.VLC'])
            ->call('planPackageCommands', 'winget-upgrade')
            ->assertSee('Nothing to upgrade.')
            ->assertDontSeeHtml('data-confirm-package-commands')
            ->call('queuePlannedPackageCommands')
            ->assertStatus(422);

        expect(DeviceCommand::query()->count())->toBe(0);
    });

    it('refuses install through the bulk bar, which has its own picker', function (): void {
        Livewire::actingAs($this->user)->test(Index::class)
            ->call('planPackageCommands', 'winget-install')
            ->assertStatus(422);
    });
});

describe('bulk uninstall', function (): void {
    it('uninstalls every selected package from every device that has it', function (): void {
        $first = fleetPc([bulkFirefox(), arpTool()]);
        $second = fleetPc([bulkFirefox()]);
        fleetPc([bulkVlc()]);

        Livewire::actingAs($this->user)->test(Index::class)
            ->set('selectedPackages', ['Mozilla.Firefox', 'ARP\\Machine\\X64\\Tool'])
            ->call('planPackageCommands', 'winget-uninstall')
            ->assertSee('Uninstall 2 apps: 3 commands across 2 devices.')
            ->call('queuePlannedPackageCommands');

        expect(DeviceCommand::query()->count())->toBe(3)
            ->and(DeviceCommand::query()->pluck('device_id')->unique()->sort()->values()->all())->toBe([$first->id, $second->id])
            ->and(DeviceCommand::query()->where('device_id', $first->id)->get()->pluck('parameters')->all())
            ->toEqualCanonicalizing([['PackageId' => 'Mozilla.Firefox', 'CloseApp' => 'false'], ['PackageId' => 'ARP\\Machine\\X64\\Tool', 'CloseApp' => 'false']]);
    });

    it('reaches only the devices in the group filter', function (): void {
        $group = DeviceGroup::factory()->create();
        $inGroup = fleetPc([bulkFirefox()], ['device_group_id' => $group->id]);
        $outside = fleetPc([bulkFirefox()]);

        Livewire::actingAs($this->user)->test(Index::class)
            ->set('groupFilter', (string) $group->id)
            ->set('selectedPackages', ['Mozilla.Firefox'])
            ->call('planPackageCommands', 'winget-uninstall')
            ->assertSee('Uninstall Mozilla.Firefox: 1 command across 1 device.')
            ->call('queuePlannedPackageCommands');

        expect(DeviceCommand::sole()->device_id)->toBe($inGroup->id)
            ->and($outside->commands()->count())->toBe(0);
    });

    it('reaches only the devices with the tag filter', function (): void {
        $tag = Tag::factory()->create();
        $tagged = fleetPc([bulkFirefox()]);
        $tagged->tags()->attach($tag);
        fleetPc([bulkFirefox()]);

        Livewire::actingAs($this->user)->test(Index::class)
            ->set('tagFilter', (string) $tag->id)
            ->set('selectedPackages', ['Mozilla.Firefox'])
            ->call('planPackageCommands', 'winget-uninstall')
            ->call('queuePlannedPackageCommands');

        expect(DeviceCommand::sole()->device_id)->toBe($tagged->id);
    });
});

describe('activity and authorisation', function (): void {
    it('shows each package\'s in-flight commands on its row', function (): void {
        fleetPc([bulkFirefox(outdated: true)]);
        fleetPc([bulkFirefox(outdated: true), bulkVlc()]);

        $page = Livewire::actingAs($this->user)->test(Index::class)
            ->assertDontSeeHtml('data-software-activity')
            ->set('selectedPackages', ['Mozilla.Firefox'])
            ->call('planPackageCommands', 'winget-upgrade')
            ->call('queuePlannedPackageCommands');

        $page->dispatch('echo-private:devices,CommandUpdated', [])
            ->assertSeeHtml('data-software-activity')
            ->assertSee('Queued: Upgrade on 2 devices');
    });

    it('refuses bulk actions to users who may not run commands', function (): void {
        fleetPc([bulkFirefox()]);
        $page = Livewire::actingAs($this->user)->test(Index::class)
            ->set('selectedPackages', ['Mozilla.Firefox'])
            ->call('planPackageCommands', 'winget-uninstall');
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'runCommands' ? false : null);

        $page->call('queuePlannedPackageCommands')->assertForbidden();

        expect(DeviceCommand::query()->count())->toBe(0);
    });

    it('never reaches monitor-only devices', function (): void {
        fleetPc([bulkFirefox()], ['is_monitor_only' => true]);
        $windows = fleetPc([bulkFirefox()]);

        Livewire::actingAs($this->user)->test(Index::class)
            ->set('selectedPackages', ['Mozilla.Firefox'])
            ->call('planPackageCommands', 'winget-uninstall')
            ->call('queuePlannedPackageCommands');

        expect(DeviceCommand::sole()->device_id)->toBe($windows->id);
    });
});
