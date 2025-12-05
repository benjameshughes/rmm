<?php

declare(strict_types=1);

use App\Livewire\Devices\Pending;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('shows pending devices and allows approve/reject', function (): void {
    $user = User::factory()->create();
    $d1 = Device::factory()->create(['hostname' => 'PEND-1']);
    $d2 = Device::factory()->create(['hostname' => 'PEND-2']);

    $this->actingAs($user)
        ->get('/devices/pending')
        ->assertSuccessful()
        ->assertSee('PEND-1')
        ->assertSee('PEND-2');

    Livewire::actingAs($user)
        ->test(Pending::class)
        ->call('approve', $d1->id)
        ->assertDispatched('notify');

    expect(Device::find($d1->id)->status)->toBe(Device::STATUS_ACTIVE);
    expect(Device::find($d1->id)->api_key)->not->toBeNull();

    Livewire::actingAs($user)
        ->test(Pending::class)
        ->call('reject', $d2->id)
        ->assertDispatched('notify');

    expect(Device::find($d2->id)->status)->toBe(Device::STATUS_REVOKED);
});

