<?php

declare(strict_types=1);

use App\Enums\AlertStatus;
use App\Livewire\Alerts\Index;
use App\Models\Alert;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('lists alerts', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->active()->create();
    Alert::factory()->triggered()->create(['device_id' => $device->id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee($device->hostname);
});

it('acknowledges a triggered alert', function (): void {
    $user = User::factory()->create();
    $alert = Alert::factory()->triggered()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('acknowledge', $alert->id);

    $alert->refresh();
    expect($alert->status)->toBe(AlertStatus::Acknowledged);
    expect($alert->acknowledged_by)->toBe($user->id);
});

it('resolves an alert', function (): void {
    $user = User::factory()->create();
    $alert = Alert::factory()->triggered()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('resolve', $alert->id);

    $alert->refresh();
    expect($alert->status)->toBe(AlertStatus::Resolved);
});

it('filters by status', function (): void {
    $user = User::factory()->create();
    $device1 = Device::factory()->active()->create(['hostname' => 'TRIGGERED-HOST']);
    $device2 = Device::factory()->active()->create(['hostname' => 'RESOLVED-HOST']);
    Alert::factory()->triggered()->create(['device_id' => $device1->id]);
    Alert::factory()->resolved()->create(['device_id' => $device2->id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('statusFilter', 'triggered')
        ->assertSee('TRIGGERED-HOST')
        ->assertDontSee('RESOLVED-HOST');
});

it('requires authentication', function (): void {
    $this->get('/alerts')->assertRedirect('/login');
});
