<?php

declare(strict_types=1);

use App\Livewire\Devices\Index;
use App\Livewire\Devices\Show;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

describe('Group assignment', function (): void {
    it('assigns a device to a group via show page', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();
        $group = DeviceGroup::factory()->create(['name' => 'Servers']);

        Livewire::actingAs($user)
            ->test(Show::class, ['device' => $device])
            ->set('selectedGroupId', (string) $group->id);

        $device->refresh();
        expect($device->device_group_id)->toBe($group->id);
    });

    it('removes a device from a group', function (): void {
        $user = User::factory()->create();
        $group = DeviceGroup::factory()->create();
        $device = Device::factory()->active()->create(['device_group_id' => $group->id]);

        Livewire::actingAs($user)
            ->test(Show::class, ['device' => $device])
            ->set('selectedGroupId', '');

        $device->refresh();
        expect($device->device_group_id)->toBeNull();
    });

    it('filters devices by group on index', function (): void {
        $user = User::factory()->create();
        $group = DeviceGroup::factory()->create(['name' => 'Servers']);
        $inGroup = Device::factory()->active()->create(['hostname' => 'SERVER-01', 'device_group_id' => $group->id]);
        $notInGroup = Device::factory()->active()->create(['hostname' => 'LAPTOP-01']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('groupFilter', (string) $group->id)
            ->assertSee('SERVER-01')
            ->assertDontSee('LAPTOP-01');
    });
});

describe('Tag assignment', function (): void {
    it('adds tags to a device via show page', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();
        $tag1 = Tag::factory()->create(['name' => 'critical']);
        $tag2 = Tag::factory()->create(['name' => 'windows']);

        Livewire::actingAs($user)
            ->test(Show::class, ['device' => $device])
            ->set('selectedTagIds', [(string) $tag1->id, (string) $tag2->id]);

        $device->refresh();
        expect($device->tags)->toHaveCount(2);
        expect($device->tags->pluck('name')->toArray())->toContain('critical', 'windows');
    });

    it('removes tags from a device', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();
        $tag = Tag::factory()->create();
        $device->tags()->attach($tag->id);

        Livewire::actingAs($user)
            ->test(Show::class, ['device' => $device])
            ->set('selectedTagIds', []);

        $device->refresh();
        expect($device->tags)->toHaveCount(0);
    });

    it('filters devices by tag on index', function (): void {
        $user = User::factory()->create();
        $tag = Tag::factory()->create(['name' => 'critical']);
        $tagged = Device::factory()->active()->create(['hostname' => 'CRITICAL-01']);
        $tagged->tags()->attach($tag->id);
        $untagged = Device::factory()->active()->create(['hostname' => 'NORMAL-01']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('tagFilter', (string) $tag->id)
            ->assertSee('CRITICAL-01')
            ->assertDontSee('NORMAL-01');
    });
});
