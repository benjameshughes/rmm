<?php

declare(strict_types=1);

use App\Enums\CommandStatus;
use App\Enums\DevicePowerState;
use App\Enums\DeviceStatus;
use App\Events\CommandUpdated;
use App\Events\DeviceUpdated;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('api.power');
});

function postPowerEvent(string $event, string $reason, string $apiKey = 'power-key'): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/power', ['event' => $event, 'reason' => $reason], ['X-Agent-Key' => $apiKey]);
}

describe('authentication', function (): void {
    it('returns 401 when no API key is provided', function (): void {
        $this->postJson('/api/power', ['event' => 'powering_off', 'reason' => 'sleep'])
            ->assertUnauthorized()
            ->assertJson(['message' => 'Missing device key.']);
    });

    it('returns 401 for an invalid API key', function (): void {
        postPowerEvent('powering_off', 'sleep', 'invalid-key')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Invalid or revoked device key.']);
    });

    it('returns 401 for a revoked device', function (): void {
        Device::factory()->withApiKey('power-key')->create(['status' => DeviceStatus::Revoked]);

        postPowerEvent('powering_off', 'sleep')->assertUnauthorized();
    });
});

describe('validation', function (): void {
    beforeEach(function (): void {
        Device::factory()->active()->withApiKey('power-key')->create();
    });

    it('rejects a bad event or reason', function (array $payload, string $invalidField): void {
        $this->postJson('/api/power', $payload, ['X-Agent-Key' => 'power-key'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$invalidField]);
    })->with([
        'missing event' => [['reason' => 'sleep'], 'event'],
        'unknown event' => [['event' => 'exploding', 'reason' => 'sleep'], 'event'],
        'event as an array' => [['event' => ['powering_off'], 'reason' => 'sleep'], 'event'],
        'missing reason' => [['event' => 'powering_off'], 'reason'],
        'unknown reason' => [['event' => 'powering_off', 'reason' => 'nap'], 'reason'],
        'powering off to resume' => [['event' => 'powering_off', 'reason' => 'resume'], 'reason'],
        'powering on to sleep' => [['event' => 'powering_on', 'reason' => 'sleep'], 'reason'],
    ]);

    it('explains a reason that does not match the event', function (): void {
        postPowerEvent('powering_on', 'shutdown')
            ->assertJsonValidationErrors(['reason' => 'That reason does not match the event']);
    });
});

describe('recording', function (): void {
    it('records each event and reason', function (string $event, string $reason): void {
        $device = Device::factory()->active()->withApiKey('power-key')->create();

        postPowerEvent($event, $reason)
            ->assertSuccessful()
            ->assertJsonStructure(['status', 'server_time'])
            ->assertJson(['status' => 'ok']);

        $device->refresh();
        expect($device->power_state)->toBe(DevicePowerState::from($event))
            ->and($device->power_state_changed_at->diffInSeconds(now()))->toBeLessThan(5);
    })->with([
        ['powering_off', 'sleep'],
        ['powering_off', 'standby'],
        ['powering_off', 'shutdown'],
        ['powering_on', 'resume'],
        ['powering_on', 'boot'],
    ]);

    it('leaves last_seen alone when the device powers off', function (): void {
        $lastSeen = now()->subMinutes(2)->startOfSecond();
        $device = Device::factory()->active()->withApiKey('power-key')->create(['last_seen' => $lastSeen, 'last_ip' => '10.0.0.5']);

        postPowerEvent('powering_off', 'sleep')->assertSuccessful();

        $device->refresh();
        expect($device->last_seen->equalTo($lastSeen))->toBeTrue()
            ->and($device->last_ip)->toBe('10.0.0.5');
    });

    it('counts powering on as a check-in', function (): void {
        $device = Device::factory()->active()->withApiKey('power-key')->create(['last_seen' => now()->subHours(8), 'last_ip' => '10.0.0.5']);

        postPowerEvent('powering_on', 'resume')->assertSuccessful();

        $device->refresh();
        expect($device->last_seen->diffInSeconds(now()))->toBeLessThan(5)
            ->and($device->last_ip)->toBe('127.0.0.1')
            ->and($device->isOnline)->toBeTrue();
    });

    it('broadcasts the change so open pages flip', function (): void {
        Event::fake([DeviceUpdated::class]);
        $device = Device::factory()->active()->withApiKey('power-key')->create();

        postPowerEvent('powering_off', 'shutdown')->assertSuccessful();

        Event::assertDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => $event->deviceId === $device->id);
    });

    it('does not audit power changes', function (): void {
        Device::factory()->active()->withApiKey('power-key')->create();
        $auditRowsBefore = AuditLog::count();

        postPowerEvent('powering_off', 'sleep')->assertSuccessful();
        postPowerEvent('powering_on', 'resume')->assertSuccessful();

        expect(AuditLog::count())->toBe($auditRowsBefore);
    });

    it('is rate limited per device', function (): void {
        Device::factory()->active()->withApiKey('power-key')->create();

        collect(range(1, 10))->each(fn () => postPowerEvent('powering_off', 'sleep')->assertSuccessful());

        postPowerEvent('powering_off', 'sleep')
            ->assertTooManyRequests()
            ->assertJson(['message' => 'Too many power events. Please try again later.']);
    });
});

describe('pending commands', function (): void {
    it('still hands out a command while the device is powering off, leaving the agent to refuse it', function (): void {
        $device = Device::factory()->active()->withApiKey('power-key')->create();
        $command = DeviceCommand::factory()->create(['device_id' => $device->id, 'status' => CommandStatus::Pending]);

        postPowerEvent('powering_off', 'standby')->assertSuccessful();

        $this->getJson('/api/commands/pending', ['X-Agent-Key' => 'power-key'])
            ->assertSuccessful()
            ->assertJsonPath('command.id', $command->id);

        expect($command->fresh()->status)->toBe(CommandStatus::Sent);
    });

    it('hands the command out again once the device has powered on', function (): void {
        $device = Device::factory()->active()->withApiKey('power-key')->create();
        $command = DeviceCommand::factory()->create(['device_id' => $device->id, 'status' => CommandStatus::Pending]);

        postPowerEvent('powering_off', 'sleep')->assertSuccessful();
        postPowerEvent('powering_on', 'resume')->assertSuccessful();

        $this->getJson('/api/commands/pending', ['X-Agent-Key' => 'power-key'])
            ->assertSuccessful()
            ->assertJsonPath('command.id', $command->id);
    });
});

describe('clearing on check-in', function (): void {
    it('clears powering off when a heartbeat arrives', function (): void {
        $device = Device::factory()->active()->withApiKey('power-key')->create();
        RateLimiter::clear('api.heartbeat');

        postPowerEvent('powering_off', 'sleep')->assertSuccessful();
        $this->travel(config('devices.power.powering_off_check_in_grace_seconds') + 1)->seconds();
        $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'power-key'])->assertSuccessful();

        $device->refresh();
        expect($device->power_state)->toBeNull()
            ->and($device->power_state_changed_at)->toBeNull()
            ->and($device->isOnline)->toBeTrue();
    });

    it('clears powering off when metrics arrive', function (): void {
        $device = Device::factory()->active()->withApiKey('power-key')->create();
        RateLimiter::clear('api.metrics');

        postPowerEvent('powering_off', 'shutdown')->assertSuccessful();
        $this->travel(config('devices.power.powering_off_check_in_grace_seconds') + 1)->seconds();
        $this->postJson('/api/metrics', ['cpu' => ['usage_percent' => 10], 'memory' => ['usage_percent' => 20]], ['X-Agent-Key' => 'power-key'])
            ->assertSuccessful();

        expect($device->refresh()->power_state)->toBeNull();
    });

    it('keeps powering off through a check-in that was already in flight when sleep began', function (string $endpoint, array $payload): void {
        // Frozen so real time spent between the two requests can't eat the one second of margin.
        $this->freezeTime();
        $device = Device::factory()->active()->withApiKey('power-key')->create(['last_seen' => now()]);
        RateLimiter::clear('api.heartbeat');
        RateLimiter::clear('api.metrics');

        postPowerEvent('powering_off', 'sleep')->assertSuccessful();
        $this->travel(config('devices.power.powering_off_check_in_grace_seconds') - 1)->seconds();
        $this->postJson($endpoint, $payload, ['X-Agent-Key' => 'power-key'])->assertSuccessful();

        $device->refresh();
        expect($device->power_state)->toBe(DevicePowerState::PoweringOff)
            ->and($device->isOnline)->toBeFalse()
            ->and($device->statusLabel())->toStartWith('Off since');
    })->with([
        'heartbeat' => ['/api/heartbeat', []],
        'metrics' => ['/api/metrics', ['cpu' => ['usage_percent' => 10]]],
    ]);

    it('lets a wake inside the grace override powering off', function (): void {
        $device = Device::factory()->active()->withApiKey('power-key')->create();

        postPowerEvent('powering_off', 'sleep')->assertSuccessful();
        postPowerEvent('powering_on', 'resume')->assertSuccessful();

        expect($device->refresh()->power_state)->toBe(DevicePowerState::PoweringOn)
            ->and($device->statusLabel())->toBe('Powering on');
    });

    it('holds powering on through an early check-in, then settles to Online', function (): void {
        $device = Device::factory()->active()->withApiKey('power-key')->create();
        RateLimiter::clear('api.heartbeat');

        postPowerEvent('powering_on', 'boot')->assertSuccessful();
        $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'power-key'])->assertSuccessful();

        expect($device->refresh()->power_state)->toBe(DevicePowerState::PoweringOn)
            ->and($device->statusLabel())->toBe('Powering on');

        $this->travel(config('devices.power.powering_on_hold_seconds') + 1)->seconds();
        $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'power-key'])->assertSuccessful();

        expect($device->refresh()->power_state)->toBeNull()
            ->and($device->statusLabel())->toBe('Online');
    });

    it('settles a held powering on when metrics arrive after the hold', function (): void {
        $device = Device::factory()->active()->withApiKey('power-key')->create();
        RateLimiter::clear('api.metrics');

        postPowerEvent('powering_on', 'resume')->assertSuccessful();
        $this->travel(config('devices.power.powering_on_hold_seconds') + 1)->seconds();
        $this->postJson('/api/metrics', ['cpu' => ['usage_percent' => 10]], ['X-Agent-Key' => 'power-key'])
            ->assertSuccessful();

        expect($device->refresh()->power_state)->toBeNull();
    });
});

describe('interrupted commands', function (): void {
    beforeEach(function (): void {
        $this->device = Device::factory()->active()->withApiKey('power-key')->create();
        $this->commandIn = fn (CommandStatus $status, ?Device $device = null): DeviceCommand => DeviceCommand::factory()->create([
            'device_id' => ($device ?? $this->device)->id,
            'status' => $status,
        ]);
    });

    it('fails the commands a shutdown cut off and broadcasts each', function (): void {
        $sent = ($this->commandIn)(CommandStatus::Sent);
        $running = ($this->commandIn)(CommandStatus::Running);
        Event::fake([CommandUpdated::class]);

        postPowerEvent('powering_off', 'shutdown')->assertSuccessful();

        collect([$sent, $running])->each(fn (DeviceCommand $command) => expect($command->fresh())
            ->status->toBe(CommandStatus::Failed)
            ->error_message->toBe('Interrupted: the device shut down or restarted while this was running')
            ->completed_at->not->toBeNull());

        Event::assertDispatchedTimes(CommandUpdated::class, 2);
        Event::assertDispatched(CommandUpdated::class, fn (CommandUpdated $event): bool => $event->commandId === $running->id
            && $event->status === CommandStatus::Failed->value);
    });

    it('completes a built-in restart or shutdown that caused the shutdown', function (string $slug): void {
        $command = DeviceCommand::factory()->create([
            'device_id' => $this->device->id,
            'script_id' => Script::factory()->system()->create(['slug' => $slug])->id,
            'status' => CommandStatus::Running,
        ]);

        postPowerEvent('powering_off', 'shutdown')->assertSuccessful();

        expect($command->fresh())
            ->status->toBe(CommandStatus::Completed)
            ->exit_code->toBe(0)
            ->output->toBe('The device is shutting down or restarting as requested.');
    })->with(['restart', 'shutdown']);

    it('still fails a user script that happens to share a restart slug', function (): void {
        $command = DeviceCommand::factory()->create([
            'device_id' => $this->device->id,
            'script_id' => Script::factory()->create(['slug' => 'restart', 'is_system' => false])->id,
            'status' => CommandStatus::Running,
        ]);

        postPowerEvent('powering_off', 'shutdown')->assertSuccessful();

        expect($command->fresh()->status)->toBe(CommandStatus::Failed);
    });

    it('leaves queued, finished and other devices\' commands alone on shutdown', function (): void {
        $pending = ($this->commandIn)(CommandStatus::Pending);
        $completed = ($this->commandIn)(CommandStatus::Completed);
        $elsewhere = ($this->commandIn)(CommandStatus::Running, Device::factory()->active()->create());

        postPowerEvent('powering_off', 'shutdown')->assertSuccessful();

        expect($pending->fresh()->status)->toBe(CommandStatus::Pending)
            ->and($completed->fresh()->status)->toBe(CommandStatus::Completed)
            ->and($elsewhere->fresh()->status)->toBe(CommandStatus::Running);
    });

    it('lets commands carry on through sleep', function (string $reason): void {
        $sent = ($this->commandIn)(CommandStatus::Sent);
        $running = ($this->commandIn)(CommandStatus::Running);

        postPowerEvent('powering_off', $reason)->assertSuccessful();

        expect($sent->fresh()->status)->toBe(CommandStatus::Sent)
            ->and($running->fresh()->status)->toBe(CommandStatus::Running)
            ->and($running->fresh()->error_message)->toBeNull();
    })->with(['sleep', 'standby']);
});
