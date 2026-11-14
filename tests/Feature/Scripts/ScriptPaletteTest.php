<?php

declare(strict_types=1);

use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Livewire\Devices\Header;
use App\Livewire\Scripts\Palette;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('loads nothing until it is opened', function (): void {
    Script::factory()->create();

    Livewire::actingAs($this->user)->test(Palette::class)
        ->assertViewHas('scripts', fn ($scripts): bool => $scripts->isEmpty());
});

it('filters by name and description and leaves internal scripts out', function (): void {
    Script::factory()->create(['name' => 'Flush DNS', 'description' => 'Clears the resolver cache', 'category' => ScriptCategory::Network]);
    Script::factory()->create(['name' => 'Empty Temp', 'description' => 'Deletes cache files']);
    Script::factory()->create(['name' => 'Reboot', 'description' => 'Restarts it']);
    Script::factory()->create(['name' => 'Secret Cache Plumbing', 'is_internal' => true]);

    Livewire::actingAs($this->user)->test(Palette::class)
        ->dispatch('open-script-palette')
        ->assertSet('showPalette', true)
        ->set('paletteSearch', 'cache')
        ->assertViewHas('scripts', fn ($scripts): bool => $scripts->pluck('name')->all() === ['Empty Temp', 'Flush DNS'])
        ->assertSee('Network');
});

it('is scoped to the device platform on a device page', function (): void {
    $device = Device::factory()->active()->windows()->create();
    Script::factory()->windows()->create(['name' => 'Win Only']);
    Script::factory()->linux()->create(['name' => 'Linux Only']);
    Script::factory()->create(['name' => 'Anywhere', 'platform' => ScriptPlatform::All]);

    Livewire::actingAs($this->user)->test(Palette::class, ['device' => $device])
        ->call('open')
        ->assertSee('Run a script on '.$device->hostname)
        ->assertViewHas('scripts', fn ($scripts): bool => $scripts->pluck('name')->all() === ['Anywhere', 'Win Only']);
});

it('hands a pick on a device page to the header, which runs it through its authorised path', function (): void {
    $device = Device::factory()->active()->windows()->create();
    $script = Script::factory()->windows()->create();

    Livewire::actingAs($this->user)->test(Palette::class, ['device' => $device])
        ->call('open')
        ->call('pick', $script->id)
        ->assertSet('showPalette', false)
        ->assertDispatched('run-device-script', scriptId: $script->id);

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->dispatch('run-device-script', scriptId: $script->id)
        ->assertSet('showScriptModal', true)
        ->assertSet('selectedScriptId', $script->id)
        ->call('runScript')
        ->assertDispatched('command-queued');

    expect(DeviceCommand::sole()->script_id)->toBe($script->id);
});

it('opens the shared Run modal elsewhere and queues through it', function (): void {
    $device = Device::factory()->active()->windows()->create();
    $script = Script::factory()->windows()->create();

    Livewire::actingAs($this->user)->test(Palette::class)
        ->call('open')
        ->call('pick', $script->id)
        ->assertNotDispatched('run-device-script')
        ->assertSet('showExecuteModal', true)
        ->assertViewHas('availableDevices', fn ($devices): bool => $devices->pluck('id')->all() === [$device->id])
        ->set('selectedDeviceIds', [$device->id])
        ->call('executeOnDevices')
        ->assertDispatched('command-queued');

    expect(DeviceCommand::sole()->device_id)->toBe($device->id);
});

it('still authorises every device when run from the palette', function (): void {
    $device = Device::factory()->active()->windows()->create();
    $script = Script::factory()->windows()->create();
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'runCommands' ? false : null);

    Livewire::actingAs($this->user)->test(Palette::class)
        ->call('pick', $script->id)
        ->set('selectedDeviceIds', [$device->id])
        ->call('executeOnDevices')
        ->assertForbidden();

    expect(DeviceCommand::count())->toBe(0);
});

it('mounts once in the app layout with its trigger', function (): void {
    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertSeeHtml('data-open-script-palette')
        ->assertSeeLivewire(Palette::class);
});
