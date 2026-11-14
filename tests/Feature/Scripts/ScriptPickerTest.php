<?php

declare(strict_types=1);

use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Exceptions\ScriptCannotBeRunDirectly;
use App\Livewire\Devices\Header;
use App\Livewire\Scripts\Index;
use App\Livewire\Scripts\Show;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

function pickerScriptNames($groups): array
{
    return $groups->flatMap(fn ($group) => $group->scripts)->pluck('name')->sort()->values()->all();
}

describe('device Run Script picker', function (): void {
    it('only offers scripts for the device platform', function (): void {
        $device = Device::factory()->active()->windows()->create();
        Script::factory()->windows()->create(['name' => 'Win Only']);
        Script::factory()->linux()->create(['name' => 'Linux Only']);
        Script::factory()->create(['name' => 'Anywhere', 'platform' => ScriptPlatform::All]);

        Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
            ->call('openScriptPicker')
            ->assertViewHas('scriptGroups', fn ($groups): bool => pickerScriptNames($groups) === ['Anywhere', 'Win Only']);
    });

    it('searches names and descriptions and groups by category', function (): void {
        $device = Device::factory()->active()->windows()->create();
        Script::factory()->windows()->create(['name' => 'Flush DNS', 'description' => 'Clears the resolver cache', 'category' => ScriptCategory::Network]);
        Script::factory()->windows()->create(['name' => 'Empty Temp', 'description' => 'Deletes the temp cache files', 'category' => ScriptCategory::Maintenance]);
        Script::factory()->windows()->create(['name' => 'Reboot', 'description' => 'Restarts it', 'category' => ScriptCategory::Power]);

        Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
            ->call('openScriptPicker')
            ->set('scriptSearch', 'cache')
            ->assertViewHas('scriptGroups', fn ($groups): bool => pickerScriptNames($groups) === ['Empty Temp', 'Flush DNS']
                && $groups->map(fn ($group) => $group->category)->all() === [ScriptCategory::Network, ScriptCategory::Maintenance])
            ->assertSee('Clears the resolver cache')
            ->set('scriptSearch', 'nothing like this')
            ->assertSee('No scripts match');
    });

    it('picks a script and queues it', function (): void {
        $device = Device::factory()->active()->windows()->create();
        $script = Script::factory()->windows()->create(['name' => 'Flush DNS']);

        Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
            ->call('openScriptPicker')
            ->call('selectScript', $script->id)
            ->assertSeeHtml('data-selected-script')
            ->call('runScript')
            ->assertDispatched('command-queued');

        expect(DeviceCommand::sole()->script_id)->toBe($script->id);
    });

    it('refuses a script for another platform', function (): void {
        $device = Device::factory()->active()->windows()->create();

        expect(fn () => Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
            ->set('selectedScriptId', Script::factory()->linux()->create()->id)
            ->call('runScript'))
            ->toThrow(fn (ScriptCannotBeRunDirectly $exception) => expect($exception->getStatusCode())->toBe(422));

        expect(DeviceCommand::count())->toBe(0);
    });
});

describe('script library Run modal', function (): void {
    it('lists only active commandable devices on the script platform', function (): void {
        Device::factory()->active()->windows()->create(['hostname' => 'WIN-1']);
        Device::factory()->active()->linux()->create(['hostname' => 'linux-1']);
        Device::factory()->active()->windows()->monitorOnly()->create(['hostname' => 'WIN-WATCH']);
        $script = Script::factory()->windows()->create();

        Livewire::actingAs($this->user)->test(Index::class)
            ->call('openRun', $script->id)
            ->assertSet('showExecuteModal', true)
            ->assertViewHas('availableDevices', fn ($devices): bool => $devices->pluck('hostname')->all() === ['WIN-1']);
    });

    it('runs from the library and drops devices off the platform', function (): void {
        $windows = Device::factory()->active()->windows()->create();
        $linux = Device::factory()->active()->linux()->create();
        $script = Script::factory()->windows()->create(['name' => 'Flush DNS']);

        Livewire::actingAs($this->user)->test(Index::class)
            ->call('openRun', $script->id)
            ->set('selectedDeviceIds', [$windows->id, $linux->id])
            ->call('executeOnDevices')
            ->assertDispatched('command-queued')
            ->assertDispatched('toast-show', fn (string $name, array $params): bool => $params['slots']['text'] === 'Queued on 1 of 2 devices.');

        expect(DeviceCommand::pluck('device_id')->all())->toBe([$windows->id]);
    });

    it('adds a whole group of eligible devices', function (): void {
        $group = DeviceGroup::factory()->create();
        $inGroup = Device::factory()->active()->windows()->create(['device_group_id' => $group->id]);
        Device::factory()->active()->linux()->create(['device_group_id' => $group->id]);
        $script = Script::factory()->windows()->create();

        Livewire::actingAs($this->user)->test(Show::class, ['script' => $script])
            ->call('addGroupToRun', $group->id)
            ->assertSet('selectedDeviceIds', [(string) $inGroup->id]);
    });

    it('authorises every device on the server', function (): void {
        $device = Device::factory()->active()->windows()->create();
        $script = Script::factory()->windows()->create();
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'runCommands' ? false : null);

        Livewire::actingAs($this->user)->test(Index::class)
            ->call('openRun', $script->id)
            ->set('selectedDeviceIds', [$device->id])
            ->call('executeOnDevices')
            ->assertForbidden();

        expect(DeviceCommand::count())->toBe(0);
    });

    it('searches the library by description and groups it', function (): void {
        Script::factory()->create(['name' => 'Flush DNS', 'description' => 'Clears the resolver cache', 'category' => ScriptCategory::Network]);
        Script::factory()->create(['name' => 'Reboot', 'description' => 'Restarts it', 'category' => ScriptCategory::Power]);

        Livewire::actingAs($this->user)->test(Index::class)
            ->set('search', 'resolver')
            ->assertSee('Flush DNS')
            ->assertDontSee('Reboot')
            ->assertSeeHtml('data-script-group="network"');
    });
});
