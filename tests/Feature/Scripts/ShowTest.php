<?php

declare(strict_types=1);

use App\Enums\CommandStatus;
use App\Enums\ScriptPlatform;
use App\Livewire\Scripts\Show;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('shows script details', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create(['platform' => ScriptPlatform::All, 'name' => 'Test Script']);

    Livewire::actingAs($user)
        ->test(Show::class, ['script' => $script])
        ->assertSee('Test Script')
        ->assertSuccessful();
});

it('shows recent command history for the script', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create(['platform' => ScriptPlatform::All]);
    $device = Device::factory()->active()->create();

    DeviceCommand::factory()->count(3)->create([
        'script_id' => $script->id,
        'device_id' => $device->id,
        'queued_by' => $user->id,
    ]);

    $component = Livewire::actingAs($user)->test(Show::class, ['script' => $script]);

    expect($component->instance()->recentCommands)->toHaveCount(3);
});

it('executes a script on a device', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create(['platform' => ScriptPlatform::All, 'script_content' => 'Get-Process']);
    $device = Device::factory()->active()->create();

    Livewire::actingAs($user)
        ->test(Show::class, ['script' => $script])
        ->set('selectedDeviceIds', [$device->id])
        ->call('executeOnDevices')
        ->assertDispatched('command-queued');

    $command = DeviceCommand::where('script_id', $script->id)->first();

    expect($command)->not->toBeNull();
    expect($command->device_id)->toBe($device->id);
    expect($command->script_content)->toBe('Get-Process');
    expect($command->status)->toBe(CommandStatus::Pending);
    expect($command->queued_by)->toBe($user->id);
});

it('requires authentication to execute', function (): void {
    $script = Script::factory()->create(['platform' => ScriptPlatform::All]);
    $device = Device::factory()->active()->create();

    Livewire::test(Show::class, ['script' => $script])
        ->set('selectedDeviceIds', [$device->id])
        ->call('executeOnDevices')
        ->assertForbidden();
});

it('runs one script on several devices at once and says how many it queued on', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create(['platform' => ScriptPlatform::All, 'name' => 'Flush DNS']);
    $devices = Device::factory()->active()->windows()->count(3)->create();

    Livewire::actingAs($user)
        ->test(Show::class, ['script' => $script])
        ->set('selectedDeviceIds', $devices->pluck('id')->all())
        ->call('executeOnDevices')
        ->assertDispatched('command-queued')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => $params['slots']['text'] === 'Queued on 3 of 3 devices.')
        ->assertSet('selectedDeviceIds', []);

    expect(DeviceCommand::where('script_id', $script->id)->pluck('device_id')->sort()->values()->all())
        ->toBe($devices->pluck('id')->sort()->values()->all());
});

it('re-renders the executions island when a command is queued elsewhere', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create(['platform' => ScriptPlatform::All]);
    $device = Device::factory()->active()->create(['hostname' => 'island-host']);

    $component = Livewire::actingAs($user)
        ->test(Show::class, ['script' => $script])
        ->assertDontSee('island-host');

    DeviceCommand::factory()->create([
        'script_id' => $script->id,
        'device_id' => $device->id,
        'queued_by' => $user->id,
    ]);

    $component->dispatch('command-queued');

    expect(islandHtml($component))->toContain('island-host');
});
