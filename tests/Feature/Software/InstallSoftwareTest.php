<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Livewire\Devices\Index as DevicesIndex;
use App\Livewire\Software\Index as SoftwareIndex;
use App\Livewire\Software\InstallSoftware;
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

function windowsPc(array $attributes = []): Device
{
    return Device::factory()->active()->windows()->create(['agent_version' => '0.7.1', ...$attributes]);
}

function withPackage(Device $device, string $packageId = 'Mozilla.Firefox'): Device
{
    DeviceSoftware::factory()->create(['device_id' => $device->id, 'package_id' => $packageId, 'name' => $packageId, 'source' => 'winget']);

    return $device;
}

function installTargets(): array
{
    return DeviceCommand::query()->pluck('device_id')->sort()->values()->all();
}

it('is opened from the software page, the devices table bulk bar and a package page', function (): void {
    windowsPc();

    Livewire::actingAs($this->user)->test(SoftwareIndex::class)
        ->assertSeeHtml('data-open-install-software')
        ->assertSeeLivewire(InstallSoftware::class);

    Livewire::actingAs($this->user)->test(DevicesIndex::class)
        ->assertSeeHtml('data-bulk-install')
        ->assertSeeHtml("\$dispatch('open-install-software', { deviceIds: \$wire.selectedDevices })")
        ->assertSeeLivewire(InstallSoftware::class);
});

it('installs on the devices ticked on the devices table, skipping ones that already have it', function (): void {
    $first = windowsPc();
    $second = windowsPc();
    $has = withPackage(windowsPc());
    $notTicked = windowsPc();

    Livewire::actingAs($this->user)->test(InstallSoftware::class)
        ->dispatch('open-install-software', deviceIds: [(string) $first->id, (string) $second->id, (string) $has->id])
        ->assertSet('target', 'selected')
        ->set('packageId', 'Mozilla.Firefox')
        ->assertSee('Install Mozilla.Firefox: 2 commands across 2 devices.')
        ->assertSee('1 device skipped: it already has it.')
        ->assertSee('Offline devices install it when they next check in.')
        ->call('install')
        ->assertHasNoErrors()
        ->assertSet('showModal', false)
        ->assertDispatched('command-queued');

    expect(installTargets())->toBe([$first->id, $second->id])
        ->and(DeviceCommand::query()->get()->every(fn (DeviceCommand $command): bool => $command->script_id === Script::findSystem('winget-install')->id
            && $command->parameters === ['PackageId' => 'Mozilla.Firefox']))->toBeTrue()
        ->and($notTicked->commands()->count())->toBe(0);
});

it('installs on a group', function (): void {
    $group = DeviceGroup::factory()->create();
    $inGroup = windowsPc(['device_group_id' => $group->id]);
    windowsPc();

    Livewire::actingAs($this->user)->test(InstallSoftware::class)
        ->dispatch('open-install-software')
        ->set('packageId', 'Mozilla.Firefox')
        ->set('target', 'group')
        ->call('install')
        ->assertHasErrors(['targetGroupId' => 'required'])
        ->set('targetGroupId', (string) $group->id)
        ->call('install')
        ->assertHasNoErrors();

    expect(installTargets())->toBe([$inGroup->id]);
});

it('installs on a tag', function (): void {
    $tag = Tag::factory()->create();
    $tagged = windowsPc();
    $tagged->tags()->attach($tag);
    windowsPc();

    Livewire::actingAs($this->user)->test(InstallSoftware::class)
        ->dispatch('open-install-software')
        ->set('packageId', 'Mozilla.Firefox')
        ->set('target', 'tag')
        ->set('targetTagId', (string) $tag->id)
        ->call('install')
        ->assertHasNoErrors();

    expect(installTargets())->toBe([$tagged->id]);
});

it('installs on every approved Windows device, offline ones included, but never Linux, monitor-only or pending devices', function (): void {
    $online = windowsPc(['last_seen' => now()]);
    $offline = Device::factory()->offline()->windows()->create(['agent_version' => '0.7.1']);
    Device::factory()->active()->linux()->create(['agent_version' => '0.7.1']);
    Device::factory()->active()->windows()->create(['agent_version' => '0.7.1', 'is_monitor_only' => true]);
    Device::factory()->windows()->create(['status' => 'pending']);
    withPackage(windowsPc());

    Livewire::actingAs($this->user)->test(InstallSoftware::class)
        ->dispatch('open-install-software')
        ->assertSet('target', 'all')
        ->set('packageId', 'Mozilla.Firefox')
        ->assertSee('Install Mozilla.Firefox: 2 commands across 2 devices.')
        ->call('install');

    expect(installTargets())->toBe([$online->id, $offline->id]);
});

it('suggests winget package IDs the fleet already has, never ARP or MSIX ones', function (): void {
    $device = withPackage(windowsPc(), 'Mozilla.Firefox');
    DeviceSoftware::factory()->fromArp()->create(['device_id' => $device->id, 'name' => 'Mozilla Thing']);

    $picker = Livewire::actingAs($this->user)->test(InstallSoftware::class)
        ->dispatch('open-install-software')
        ->set('packageId', 'mozilla');

    expect($picker->instance()->suggestions->all())->toBe(['Mozilla.Firefox']);
});

it('rejects package IDs winget-install would refuse, and queues nothing', function (string $packageId, string $rule): void {
    windowsPc();

    Livewire::actingAs($this->user)->test(InstallSoftware::class)
        ->dispatch('open-install-software')
        ->set('packageId', $packageId)
        ->call('install')
        ->assertHasErrors(['packageId' => $rule]);

    expect(DeviceCommand::query()->count())->toBe(0);
})->with([
    'blank' => ['   ', 'required'],
    'spaces' => ['Mozilla Firefox', 'regex'],
    'shell characters' => ['Mozilla.Firefox; rm -rf', 'regex'],
    'leading dot' => ['.Mozilla', 'regex'],
    'arp entry' => ['ARP\\Machine\\X64\\Tool', 'regex'],
    'too long' => [str_repeat('a', 129), 'max'],
]);

it('accepts a typed winget ID nobody has yet', function (): void {
    $device = windowsPc();

    Livewire::actingAs($this->user)->test(InstallSoftware::class)
        ->dispatch('open-install-software')
        ->set('packageId', 'Notepad++.Notepad++')
        ->call('install')
        ->assertHasNoErrors();

    expect(DeviceCommand::sole())->device_id->toBe($device->id)->parameters->toBe(['PackageId' => 'Notepad++.Notepad++']);
});

it('says so when every target already has it', function (): void {
    withPackage(windowsPc());

    Livewire::actingAs($this->user)->test(InstallSoftware::class)
        ->dispatch('open-install-software')
        ->set('packageId', 'Mozilla.Firefox')
        ->call('install')
        ->assertHasErrors('target');

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('refuses users who may not run commands', function (): void {
    windowsPc();
    $picker = Livewire::actingAs($this->user)->test(InstallSoftware::class)
        ->dispatch('open-install-software')
        ->set('packageId', 'Mozilla.Firefox');
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'runCommands' ? false : null);

    $picker->call('install')->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('refuses to open for users who may not see devices', function (): void {
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'viewAny' ? false : null);

    Livewire::actingAs($this->user)->test(InstallSoftware::class)
        ->dispatch('open-install-software')
        ->assertForbidden();
});
