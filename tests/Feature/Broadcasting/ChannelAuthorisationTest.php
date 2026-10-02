<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

pest()->use(RefreshDatabase::class);

/**
 * Channels register on whichever broadcaster is default at boot (null in tests),
 * so swap to a local-only Reverb config and register them again on it.
 */
beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'reverb.invalid',
    ]);

    require base_path('routes/channels.php');
});

function authoriseChannel(string $channel): Illuminate\Testing\TestResponse
{
    return test()->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => $channel,
    ]);
}

it('lets an authenticated user join the fleet channel', function (): void {
    $this->actingAs(User::factory()->create());

    authoriseChannel('private-devices')
        ->assertSuccessful()
        ->assertJsonStructure(['auth']);
});

it('lets an authenticated user join a device channel', function (): void {
    $device = Device::factory()->create();
    $this->actingAs(User::factory()->create());

    authoriseChannel("private-devices.{$device->id}")
        ->assertSuccessful()
        ->assertJsonStructure(['auth']);
});

it('refuses guests on every channel', function (string $channel): void {
    $device = Device::factory()->create();

    authoriseChannel(str_replace('{id}', (string) $device->id, $channel))->assertForbidden();
})->with([
    'fleet' => 'private-devices',
    'device' => 'private-devices.{id}',
]);

it('refuses a channel for a device that does not exist', function (): void {
    $this->actingAs(User::factory()->create());

    authoriseChannel('private-devices.999999')->assertForbidden();
});

it('authorises channels through the device policy', function (string $ability, string $channel): void {
    $device = Device::factory()->create();
    $this->actingAs(User::factory()->create());

    Gate::before(fn (User $user, string $checked): ?bool => $checked === $ability ? false : null);

    authoriseChannel(str_replace('{id}', (string) $device->id, $channel))->assertForbidden();
})->with([
    'fleet' => ['viewAny', 'private-devices'],
    'device' => ['view', 'private-devices.{id}'],
]);

it('exposes the csrf token Echo needs to authorise private channels', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('devices.index'))
        ->assertSuccessful()
        ->assertSee('<meta name="csrf-token" content="'.csrf_token().'" />', false);
});

it('lets a user join their own notification channel', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    authoriseChannel("private-App.Models.User.{$user->id}")
        ->assertSuccessful()
        ->assertJsonStructure(['auth']);
});

it('refuses a user on someone else\'s notification channel', function (): void {
    $colleague = User::factory()->create();
    $this->actingAs(User::factory()->create());

    authoriseChannel("private-App.Models.User.{$colleague->id}")->assertForbidden();
});
