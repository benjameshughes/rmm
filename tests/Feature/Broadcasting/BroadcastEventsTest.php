<?php

declare(strict_types=1);

use App\Actions\Device\SyncDeviceTags;
use App\Enums\AlertStatus;
use App\Enums\CommandStatus;
use App\Enums\DeviceStatus;
use App\Events\AlertChanged;
use App\Events\AuditLogged;
use App\Events\CommandProgressed;
use App\Events\CommandUpdated;
use App\Events\DeviceEnrolled;
use App\Events\DeviceUpdated;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

pest()->use(RefreshDatabase::class);

/** @return array<int, string> */
function channelNames(ShouldBroadcast $event): array
{
    return collect($event->broadcastOn())->map(fn (PrivateChannel $channel): string => $channel->name)->all();
}

it('announces a newly enrolled device on the fleet channel', function (): void {
    Event::fake([DeviceEnrolled::class, DeviceUpdated::class]);

    $device = Device::factory()->create();

    Event::assertDispatched(DeviceEnrolled::class, fn (DeviceEnrolled $event): bool => $event->deviceId === $device->id
        && channelNames($event) === ['private-devices']
        && $event->broadcastWith() === ['deviceId' => $device->id]);
    Event::assertNotDispatched(DeviceUpdated::class);
});

it('announces device updates on the fleet and device channels', function (): void {
    $device = Device::factory()->create();
    Event::fake([DeviceUpdated::class]);

    $device->issueApiKey();

    Event::assertDispatchedTimes(DeviceUpdated::class, 1);
    Event::assertDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => channelNames($event) === ['private-devices', "private-devices.{$device->id}"]
        && $event->broadcastWith() === ['deviceId' => $device->id, 'status' => DeviceStatus::Active->value, 'isStateChange' => true]);
});

it('announces a device update once per heartbeat', function (): void {
    $device = Device::factory()->withApiKey('KEY-HEARTBEAT')->create();
    Event::fake([DeviceUpdated::class]);

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-HEARTBEAT'])->assertSuccessful();

    Event::assertDispatchedTimes(DeviceUpdated::class, 1);
});

it('announces a device update for reject, reset enrolment and key claim', function (): void {
    $device = Device::factory()->awaitingKeyClaim('KEY-CLAIM')->create();
    Event::fake([DeviceUpdated::class]);

    $device->claimPendingApiKey();
    $device->resetEnrolment();
    $device->forceFill(['status' => DeviceStatus::Revoked])->save();

    Event::assertDispatchedTimes(DeviceUpdated::class, 3);
});

it('announces tag changes but not a no-op sync', function (): void {
    $device = Device::factory()->create();
    $tag = Tag::factory()->create();
    Event::fake([DeviceUpdated::class]);

    app(SyncDeviceTags::class)($device, [$tag->id]);
    app(SyncDeviceTags::class)($device, [$tag->id]);

    Event::assertDispatchedTimes(DeviceUpdated::class, 1);
});

it('does not announce a save that changed nothing', function (): void {
    $device = Device::factory()->create();
    Event::fake([DeviceUpdated::class]);

    $device->save();

    Event::assertNotDispatched(DeviceUpdated::class);
});

it('announces command creation and every status change', function (): void {
    Event::fake([CommandUpdated::class]);

    $command = DeviceCommand::factory()->pending()->create();
    $command->markAsSent();
    $command->markAsRunning();
    $command->markAsCompleted('secret output', 0);

    Event::assertDispatchedTimes(CommandUpdated::class, 4);
    Event::assertDispatched(CommandUpdated::class, fn (CommandUpdated $event): bool => $event->status === CommandStatus::Completed->value
        && channelNames($event) === ['private-devices', "private-devices.{$command->device_id}"]
        && $event->broadcastWith() === [
            'commandId' => $command->id,
            'deviceId' => $command->device_id,
            'status' => CommandStatus::Completed->value,
        ]);
});

it('announces a cancelled command', function (): void {
    $command = DeviceCommand::factory()->pending()->create();
    Event::fake([CommandUpdated::class]);

    $command->cancel();

    Event::assertDispatched(CommandUpdated::class, fn (CommandUpdated $event): bool => $event->status === CommandStatus::Cancelled->value);
});

it('never puts command output, script content or errors in the payload', function (): void {
    Event::fake([CommandUpdated::class]);

    $command = DeviceCommand::factory()->create([
        'script_content' => 'Write-Output TOP-SECRET-SCRIPT',
    ]);
    $command->markAsFailed('TOP-SECRET-ERROR', 'TOP-SECRET-OUTPUT', 1);

    Event::assertDispatched(CommandUpdated::class, function (CommandUpdated $event): bool {
        $serialised = json_encode($event->broadcastWith()).serialize($event);

        return array_keys($event->broadcastWith()) === ['commandId', 'deviceId', 'status']
            && ! str_contains($serialised, 'TOP-SECRET');
    });
});

it('announces progress on the fleet and device channels without the file being worked on', function (): void {
    $command = DeviceCommand::factory()->create([
        'status' => CommandStatus::Running,
        'progress' => ['schema' => 'rmm.progress/1', 'current' => 'C:\\Users\\TOP-SECRET.xlsx'],
    ]);

    $event = new CommandProgressed($command);

    expect(channelNames($event))->toBe(['private-devices', "private-devices.{$command->device_id}"])
        ->and($event->broadcastWith())->toBe(['commandId' => $command->id, 'deviceId' => $command->device_id])
        ->and(serialize($event))->not->toContain('TOP-SECRET');
});

it('never puts device secrets or hostnames in the payload', function (): void {
    Event::fake([DeviceUpdated::class, DeviceEnrolled::class]);

    $device = Device::factory()->create([
        'hostname' => 'SECRET-HOSTNAME',
        'hardware_fingerprint' => 'SECRET-FINGERPRINT',
    ]);
    $device->issueApiKey();

    $leaks = fn (ShouldBroadcast $event): bool => str_contains(json_encode($event->broadcastWith()).serialize($event), 'SECRET-')
        || str_contains(serialize($event), (string) $device->api_key_hash);

    Event::assertDispatched(DeviceEnrolled::class, fn (DeviceEnrolled $event): bool => ! $leaks($event));
    Event::assertDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => ! $leaks($event));
});

it('announces alert creation, acknowledgement and resolution on the fleet channel', function (): void {
    Event::fake([AlertChanged::class]);

    $alert = Alert::factory()->triggered()->create(['message' => 'SECRET-MESSAGE']);
    $alert->acknowledge(User::factory()->create());
    $alert->resolve();

    $broadcasts = Event::dispatched(AlertChanged::class)
        ->map(fn (array $arguments): AlertChanged => $arguments[0])
        ->filter(fn (AlertChanged $event): bool => $event->broadcastWhen());

    expect($broadcasts)->toHaveCount(3)
        ->and($broadcasts->map(fn (AlertChanged $event): string => $event->status)->values()->all())
        ->toBe([AlertStatus::Triggered->value, AlertStatus::Acknowledged->value, AlertStatus::Resolved->value]);

    $broadcasts->each(fn (AlertChanged $event) => expect(channelNames($event))->toBe(['private-devices'])
        ->and(array_keys($event->broadcastWith()))->toBe(['alertId', 'deviceId', 'status'])
        ->and(json_encode($event->broadcastWith()))->not->toContain('SECRET-MESSAGE'));
});

it('does not broadcast when an ongoing alert only refreshes its current value', function (): void {
    $alert = Alert::factory()->triggered()->create();
    Event::fake([AlertChanged::class]);

    $alert->update(['current_value' => $alert->current_value + 1]);
    Alert::query()->findOrFail($alert->id)->update(['current_value' => $alert->current_value + 1]);

    Event::assertDispatchedTimes(AlertChanged::class, 2);
    Event::assertNotDispatched(AlertChanged::class, fn (AlertChanged $event): bool => $event->broadcastWhen());
});

it('queues broadcasts after commit and rescues broadcast failures', function (string $event): void {
    expect(class_implements($event))
        ->toContain(ShouldBroadcast::class)
        ->toContain(ShouldRescue::class)
        ->toContain(ShouldDispatchAfterCommit::class);
})->with([
    DeviceEnrolled::class,
    DeviceUpdated::class,
    CommandUpdated::class,
    CommandProgressed::class,
    AlertChanged::class,
    AuditLogged::class,
]);

it('announces audit entries on the audit channel with scalars only', function (): void {
    Event::fake([AuditLogged::class]);

    $auditLog = AuditLog::factory()->create(['properties' => ['label' => 'SECRET-LABEL', 'command' => 'SECRET-COMMAND']]);

    Event::assertDispatched(AuditLogged::class, fn (AuditLogged $event): bool => channelNames($event) === ['private-audit']
        && $event->broadcastWith() === ['auditLogId' => $auditLog->id, 'action' => $auditLog->action->value]
        && ! str_contains(serialize($event), 'SECRET-'));
});

it('does not broadcast a heartbeat from a device that was already online', function (): void {
    $device = Device::factory()->active()->create(['last_seen' => now()->subSeconds(10)]);

    $device->forceFill(['last_seen' => now()])->save();

    expect((new App\Events\DeviceUpdated($device))->broadcastWhen())->toBeFalse();
});

it('broadcasts when a device that was offline checks in again', function (): void {
    $device = Device::factory()->active()->create(['last_seen' => now()->subHour()]);

    $device->forceFill(['last_seen' => now()])->save();

    expect((new App\Events\DeviceUpdated($device))->broadcastWhen())->toBeTrue();
});

it('broadcasts when something worth showing changes', function (string $attribute, mixed $value): void {
    $device = Device::factory()->active()->create(['last_seen' => now()]);

    $device->forceFill([$attribute => $value])->save();

    expect((new App\Events\DeviceUpdated($device))->broadcastWhen())->toBeTrue();
})->with([
    'agent version' => ['agent_version', '9.9.9'],
    'hostname' => ['hostname', 'RENAMED-PC'],
    'power state' => ['power_state', App\Enums\DevicePowerState::PoweringOff],
]);

it('always broadcasts deliberate announcements', function (): void {
    $device = Device::factory()->active()->create(['last_seen' => now()]);

    expect((new App\Events\DeviceUpdated($device, alwaysBroadcast: true))->broadcastWhen())->toBeTrue();
});

it('announces new metrics so open pages show them', function (): void {
    Event::fake([App\Events\DeviceUpdated::class]);
    $device = Device::factory()->active()->withApiKey('BCAST-KEY')->create(['last_seen' => now()]);

    $this->withHeaders(['X-Device-Key' => 'BCAST-KEY'])->postJson('/api/metrics', ['cpu' => 10, 'ram' => 20])->assertSuccessful();

    Event::assertDispatched(App\Events\DeviceUpdated::class, fn (App\Events\DeviceUpdated $event): bool => $event->deviceId === $device->id && $event->broadcastWhen());
});
