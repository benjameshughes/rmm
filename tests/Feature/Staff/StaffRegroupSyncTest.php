<?php

declare(strict_types=1);

use App\Actions\Device\AssignDeviceGroup;
use App\Actions\Device\SyncDeviceTags;
use App\Actions\Script\SyncSystemScripts;
use App\Events\DeviceRegrouped;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\Script;
use App\Models\StaffAccount;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();

    $this->actingAs(User::factory()->create());
    $this->group = DeviceGroup::factory()->create();
    $this->tag = Tag::factory()->create();
    $this->device = Device::factory()->windows()->active()->create(['agent_version' => '0.7.1']);
    $this->account = StaffAccount::factory()->create(['username' => 'sophie']);
});

/** @return array<int, string> Usernames per queued command */
function regroupQueued(string $slug): array
{
    return DeviceCommand::query()
        ->where('script_id', Script::findSystem($slug)->id)
        ->get()
        ->map(fn (DeviceCommand $command): string => "{$command->device_id}:{$command->parameters['Username']}")
        ->all();
}

it('syncs an account to a PC that joins its group', function (): void {
    $this->account->deviceGroups()->attach($this->group);

    app(AssignDeviceGroup::class)($this->device, $this->group);

    expect(regroupQueued('sync-local-user'))->toBe(["{$this->device->id}:sophie"])
        ->and(regroupQueued('remove-local-user'))->toBe([]);
});

it('removes an account from a PC that leaves its group', function (): void {
    $this->device->update(['device_group_id' => $this->group->id]);
    $this->account->deviceGroups()->attach($this->group);

    app(AssignDeviceGroup::class)($this->device, null);

    expect(regroupQueued('remove-local-user'))->toBe(["{$this->device->id}:sophie"])
        ->and(regroupQueued('sync-local-user'))->toBe([]);
});

it('syncs on joining a tag and removes on leaving it', function (): void {
    $this->account->tags()->attach($this->tag);

    app(SyncDeviceTags::class)($this->device, [$this->tag->id]);
    expect(regroupQueued('sync-local-user'))->toBe(["{$this->device->id}:sophie"]);

    app(SyncDeviceTags::class)($this->device, []);
    expect(regroupQueued('remove-local-user'))->toBe(["{$this->device->id}:sophie"]);
});

it('keeps an account that still targets the PC another way', function (): void {
    $this->device->update(['device_group_id' => $this->group->id]);
    $this->account->deviceGroups()->attach($this->group);
    $this->account->tags()->attach($this->tag);

    app(SyncDeviceTags::class)($this->device, [$this->tag->id]);

    expect(regroupQueued('remove-local-user'))->toBe([])
        ->and(regroupQueued('sync-local-user'))->toBe(["{$this->device->id}:sophie"]);
});

it('fires nothing when membership does not change', function (): void {
    Event::fake([DeviceRegrouped::class]);
    $this->device->update(['device_group_id' => $this->group->id]);
    $this->device->tags()->attach($this->tag);

    app(AssignDeviceGroup::class)($this->device, $this->group);
    app(SyncDeviceTags::class)($this->device, [$this->tag->id]);

    Event::assertNotDispatched(DeviceRegrouped::class);
});

it('queues nothing for a Linux PC', function (): void {
    $linux = Device::factory()->linux()->active()->create(['agent_version' => '0.7.1']);
    $this->account->deviceGroups()->attach($this->group);

    app(AssignDeviceGroup::class)($linux, $this->group);

    expect(DeviceCommand::query()->count())->toBe(0);
});
