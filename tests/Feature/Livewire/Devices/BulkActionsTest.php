<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Livewire\Devices\Index;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\Script;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(fn () => app(SyncSystemScripts::class)());

it('queues restart for all selected devices', function (): void {
    $user = User::factory()->create();
    $device1 = Device::factory()->active()->create();
    $device2 = Device::factory()->active()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('selectedDevices', [(string) $device1->id, (string) $device2->id])
        ->call('bulkRestart')
        ->assertDispatched('command-queued');

    expect(DeviceCommand::count())->toBe(2);
    expect(DeviceCommand::where('device_id', $device1->id)->first()->script_id)->toBe(Script::findSystem('restart')->id);
    expect(DeviceCommand::where('device_id', $device2->id)->first()->script_id)->toBe(Script::findSystem('restart')->id);
});

it('queues power off for all selected devices', function (): void {
    $user = User::factory()->create();
    $device1 = Device::factory()->active()->create();
    $device2 = Device::factory()->active()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('selectedDevices', [(string) $device1->id, (string) $device2->id])
        ->call('bulkPowerOff')
        ->assertDispatched('command-queued');

    expect(DeviceCommand::count())->toBe(2);
});

it('executes a script on all selected devices', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create(['script_content' => 'Get-Service']);
    $device1 = Device::factory()->active()->create();
    $device2 = Device::factory()->active()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('selectedDevices', [(string) $device1->id, (string) $device2->id])
        ->set('bulkScriptId', $script->id)
        ->call('bulkRunScript')
        ->assertDispatched('command-queued');

    expect(DeviceCommand::count())->toBe(2);
    expect(DeviceCommand::where('script_id', $script->id)->count())->toBe(2);
});

it('skips non-active devices in bulk operations', function (): void {
    $user = User::factory()->create();
    $active = Device::factory()->active()->create();
    $pending = Device::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('selectedDevices', [(string) $active->id, (string) $pending->id])
        ->call('bulkRestart');

    expect(DeviceCommand::count())->toBe(1);
    expect(DeviceCommand::first()->device_id)->toBe($active->id);
});

it('clears selection after bulk action', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->active()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('selectedDevices', [(string) $device->id])
        ->call('bulkRestart')
        ->assertSet('selectedDevices', [])
        ->assertSet('selectAll', false);
});

it('selects devices by group', function (): void {
    $user = User::factory()->create();
    $group = DeviceGroup::factory()->create();
    $inGroup = Device::factory()->active()->create(['device_group_id' => $group->id]);
    $notInGroup = Device::factory()->active()->create();

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('selectByGroup', $group->id);

    expect($component->get('selectedDevices'))->toContain((string) $inGroup->id);
    expect($component->get('selectedDevices'))->not->toContain((string) $notInGroup->id);
});

it('selects devices by tag', function (): void {
    $user = User::factory()->create();
    $tag = Tag::factory()->create();
    $tagged = Device::factory()->active()->create();
    $tagged->tags()->attach($tag->id);
    $untagged = Device::factory()->active()->create();

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('selectByTag', $tag->id);

    expect($component->get('selectedDevices'))->toContain((string) $tagged->id);
    expect($component->get('selectedDevices'))->not->toContain((string) $untagged->id);
});

it('requires authentication for bulk actions', function (): void {
    $device = Device::factory()->active()->create();

    Livewire::test(Index::class)
        ->assertForbidden();

    expect($device->commands()->count())->toBe(0);
});

it('keeps ticking devices in the browser until an action runs', function (): void {
    $device = Device::factory()->active()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(Index::class)
        ->assertSeeHtml('wire:model="selectedDevices" value="'.$device->id.'"')
        ->assertDontSeeHtml('wire:model.live="selectedDevices"');
});

it('renders the floating bulk bar driven by the ticked count, without a server-rendered card', function (): void {
    Device::factory()->active()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(Index::class)
        ->assertSeeHtml('data-bulk-bar')
        ->assertSeeHtml('x-show="count > 0"')
        ->assertSeeHtml('$wire.selectedDevices.length')
        ->assertSeeHtml('data-bulk-group')
        ->assertSeeHtml('data-bulk-tags')
        ->assertDontSeeHtml('!bg-blue-50');
});

it('selects every matching device across pages from the header checkbox', function (): void {
    config(['devices.list.per_page' => 1]);
    $devices = Device::factory()->active()->count(2)->create();

    Livewire::actingAs(User::factory()->create())
        ->test(Index::class)
        ->set('selectAll', true)
        ->assertSet('selectedDevices', fn (array $selected): bool => collect($selected)->sort()->values()->all() === $devices->pluck('id')->map(fn (int $id): string => (string) $id)->sort()->values()->all())
        ->call('nextPage')
        ->assertCount('selectedDevices', 2)
        ->set('selectAll', false)
        ->assertSet('selectedDevices', []);
});
