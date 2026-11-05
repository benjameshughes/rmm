<?php

declare(strict_types=1);

use App\Actions\Device\DeleteDeviceGroup;
use App\Actions\Device\DeleteTag;
use App\Actions\Script\SyncSystemScripts;
use App\Livewire\DeviceGroups\Index as DeviceGroupsIndex;
use App\Livewire\Tags\Index as TagsIndex;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\Script;
use App\Models\StaffAccount;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->group = DeviceGroup::factory()->create();
    $this->tag = Tag::factory()->create();
    $this->device = Device::factory()->windows()->active()->create(['agent_version' => '0.7.1', 'device_group_id' => $this->group->id]);
    $this->device->tags()->attach($this->tag);
    $this->account = StaffAccount::factory()->create(['username' => 'sophie']);
});

/** @return array<int, string> */
function deletionQueued(string $slug): array
{
    return DeviceCommand::query()
        ->where('script_id', Script::findSystem($slug)->id)
        ->get()
        ->map(fn (DeviceCommand $command): string => "{$command->device_id}:{$command->parameters['Username']}")
        ->all();
}

it('removes an account from the PCs of a deleted group it targeted', function (): void {
    $this->account->deviceGroups()->attach($this->group);

    app(DeleteDeviceGroup::class)($this->group);

    expect(DeviceGroup::query()->exists())->toBeFalse()
        ->and($this->device->fresh()->device_group_id)->toBeNull()
        ->and(deletionQueued('remove-local-user'))->toBe(["{$this->device->id}:sophie"])
        ->and(deletionQueued('sync-local-user'))->toBe([]);
});

it('removes an account from the PCs of a deleted tag it targeted', function (): void {
    $this->account->tags()->attach($this->tag);

    app(DeleteTag::class)($this->tag);

    expect(Tag::query()->exists())->toBeFalse()
        ->and(deletionQueued('remove-local-user'))->toBe(["{$this->device->id}:sophie"]);
});

it('re-syncs rather than removes when the account still targets the PC another way', function (): void {
    $this->account->tags()->attach($this->tag);
    $this->account->deviceGroups()->attach($this->group);

    app(DeleteTag::class)($this->tag);

    expect(deletionQueued('remove-local-user'))->toBe([])
        ->and(deletionQueued('sync-local-user'))->toBe(["{$this->device->id}:sophie"]);
});

it('deletes through the Actions from the Groups and Tags pages', function (): void {
    $this->account->deviceGroups()->attach($this->group);
    $this->account->tags()->attach($this->tag);

    Livewire::test(TagsIndex::class)->call('delete', $this->tag->id);
    Livewire::test(DeviceGroupsIndex::class)->call('delete', $this->group->id);

    expect(deletionQueued('remove-local-user'))->toBe(["{$this->device->id}:sophie"]);
});
