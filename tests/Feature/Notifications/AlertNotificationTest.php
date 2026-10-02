<?php

declare(strict_types=1);

use App\Livewire\AlertBell;
use App\Models\Alert;
use App\Models\Device;
use App\Models\User;
use App\Notifications\AlertTriggered;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

function broadcastNotificationEvent(User $user): string
{
    return "echo-private:App.Models.User.{$user->id},.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated";
}

it('notifies every user when an alert triggers', function (): void {
    Notification::fake();
    $colleague = User::factory()->create();

    $alert = Alert::factory()->triggered()->create();

    Notification::assertSentTo([$this->user, $colleague], AlertTriggered::class, fn (AlertTriggered $notification): bool => $notification->alert->is($alert));
    Notification::assertSentTimes(AlertTriggered::class, 2);
});

it('does not notify again while an alert carries on, gets acknowledged or resolves', function (): void {
    $alert = Alert::factory()->triggered()->create();
    Notification::fake();

    $alert->update(['current_value' => 99.9]);
    $alert->acknowledge($this->user);
    $alert->resolve();

    Notification::assertNothingSent();
});

it('does not notify for alerts created already resolved', function (): void {
    Notification::fake();

    Alert::factory()->resolved()->create();

    Notification::assertNothingSent();
});

it('sends over the database and broadcast channels', function (): void {
    $alert = Alert::factory()->triggered()->create();

    expect((new AlertTriggered($alert))->via($this->user))->toBe(['database', 'broadcast']);
});

it('stores the alert details in the database', function (): void {
    $device = Device::factory()->create(['hostname' => 'BURNING-BOX']);
    $alert = Alert::factory()->triggered()->create(['device_id' => $device->id]);

    $notification = $this->user->notifications()->sole();

    expect($notification->type)->toBe(AlertTriggered::class)
        ->and($notification->read_at)->toBeNull()
        ->and($notification->data)->toMatchArray([
            'alertId' => $alert->id,
            'deviceId' => $device->id,
            'hostname' => 'BURNING-BOX',
            'severity' => $alert->severity->value,
        ]);
});

it('keeps hostnames and messages off the socket', function (): void {
    $device = Device::factory()->create(['hostname' => 'SECRET-HOST']);
    $alert = Alert::factory()->triggered()->create(['device_id' => $device->id]);

    $payload = (new AlertTriggered($alert))->toBroadcast($this->user)->data;

    expect($payload)->toBe(['alertId' => $alert->id, 'severity' => $alert->severity->value])
        ->and(json_encode($payload))->not->toContain('SECRET-HOST');
});

it('shows unread notifications in the bell', function (): void {
    $device = Device::factory()->create(['hostname' => 'NOISY-BOX']);
    Alert::factory()->triggered()->create(['device_id' => $device->id]);

    Livewire::withoutLazyLoading()->actingAs($this->user)->test(AlertBell::class)
        ->assertViewHas('unreadCount', 1)
        ->assertSee('NOISY-BOX');
});

it('only shows the user their own notifications', function (): void {
    Alert::factory()->triggered()->create();
    $this->user->notifications()->delete();

    Livewire::withoutLazyLoading()->actingAs($this->user)->test(AlertBell::class)
        ->assertViewHas('unreadCount', 0)
        ->assertSee('No notifications');
});

it('marks a notification read and opens the device', function (): void {
    $alert = Alert::factory()->triggered()->create();
    $notification = $this->user->notifications()->sole();

    Livewire::withoutLazyLoading()->actingAs($this->user)->test(AlertBell::class)
        ->call('open', $notification->id)
        ->assertRedirect(route('devices.show', $alert->device_id));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('refuses to open another user\'s notification', function (): void {
    $colleague = User::factory()->create();
    Alert::factory()->triggered()->create();

    Livewire::withoutLazyLoading()->actingAs($this->user)->test(AlertBell::class)
        ->call('open', $colleague->notifications()->sole()->id);
})->throws(ModelNotFoundException::class);

it('marks every notification read', function (): void {
    Alert::factory()->count(3)->triggered()->create();

    Livewire::withoutLazyLoading()->actingAs($this->user)->test(AlertBell::class)
        ->assertViewHas('unreadCount', 3)
        ->call('markAllAsRead')
        ->assertViewHas('unreadCount', 0);
});

it('refreshes and toasts when a notification arrives over Reverb', function (): void {
    $component = Livewire::withoutLazyLoading()->actingAs($this->user)->test(AlertBell::class, ['showsToasts' => true])
        ->assertDontSeeHtml('wire:poll')
        ->assertViewHas('unreadCount', 0);

    $device = Device::factory()->create(['hostname' => 'FRESH-FIRE']);
    $alert = Alert::factory()->triggered()->create(['device_id' => $device->id]);
    $notification = $this->user->notifications()->sole();

    $component->dispatch(broadcastNotificationEvent($this->user), ['id' => $notification->id, 'alertId' => $alert->id])
        ->assertViewHas('unreadCount', 1)
        ->assertSee('FRESH-FIRE')
        ->assertDispatched('toast-show');
});

it('refreshes without toasting on the bell that does not own toasts', function (): void {
    $component = Livewire::withoutLazyLoading()->actingAs($this->user)->test(AlertBell::class);

    Alert::factory()->triggered()->create();

    $component->dispatch(broadcastNotificationEvent($this->user), ['id' => $this->user->notifications()->sole()->id])
        ->assertViewHas('unreadCount', 1)
        ->assertNotDispatched('toast-show');
});
