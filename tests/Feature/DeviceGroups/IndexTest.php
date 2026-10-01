<?php

declare(strict_types=1);

use App\Livewire\DeviceGroups\Index;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('lists groups with device counts', function (): void {
    $user = User::factory()->create();
    $group = DeviceGroup::factory()->create(['name' => 'Servers']);
    Device::factory()->active()->create(['device_group_id' => $group->id]);
    Device::factory()->active()->create(['device_group_id' => $group->id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Servers')
        ->assertSee('2');
});

it('creates a group', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('name', 'Warehouse PCs')
        ->set('description', 'All warehouse machines')
        ->set('color', 'blue')
        ->call('create')
        ->assertHasNoErrors();

    expect(DeviceGroup::where('name', 'Warehouse PCs')->exists())->toBeTrue();
});

it('validates unique group name', function (): void {
    $user = User::factory()->create();
    DeviceGroup::factory()->create(['name' => 'Servers']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('name', 'Servers')
        ->call('create')
        ->assertHasErrors(['name']);
});

it('edits a group', function (): void {
    $user = User::factory()->create();
    $group = DeviceGroup::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $group->id)
        ->set('name', 'New Name')
        ->call('update')
        ->assertHasNoErrors();

    expect(DeviceGroup::find($group->id)->name)->toBe('New Name');
});

it('deletes a group', function (): void {
    $user = User::factory()->create();
    $group = DeviceGroup::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('delete', $group->id);

    expect(DeviceGroup::find($group->id))->toBeNull();
});

it('requires authentication', function (): void {
    $this->get('/device-groups')->assertRedirect('/login');
});
