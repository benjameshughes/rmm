<?php

declare(strict_types=1);

use App\Actions\Alert\EvaluateAlertRules;
use App\Actions\Device\RecordDevicePowerEvent;
use App\Enums\AlertOperator;
use App\Enums\DevicePowerState;
use App\Enums\PowerEventReason;
use App\Events\DeviceUpdated;
use App\Livewire\Devices\Header;
use App\Livewire\Devices\Index;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('maps every reason to the event it belongs to', function (DevicePowerState $powerState, array $reasons): void {
    expect($powerState->reasons())->toBe($reasons);
})->with([
    'powering off' => [DevicePowerState::PoweringOff, [PowerEventReason::Sleep, PowerEventReason::Standby, PowerEventReason::Shutdown]],
    'powering on' => [DevicePowerState::PoweringOn, [PowerEventReason::Resume, PowerEventReason::Boot]],
]);

it('takes a device offline the moment it announces powering off', function (): void {
    $device = Device::factory()->active()->create(['last_seen' => now()]);

    expect($device->isOnline)->toBeTrue();

    app(RecordDevicePowerEvent::class)($device, DevicePowerState::PoweringOff, PowerEventReason::Sleep);

    expect($device->isOnline)->toBeFalse()
        ->and($device->fresh()->isOnline)->toBeFalse();
});

it('labels and colours a device from its power state', function (array $attributes, string $label, string $color): void {
    $device = Device::factory()->active()->create($attributes);

    expect($device->statusLabel())->toBe($label)
        ->and($device->statusColor())->toBe($color);
})->with([
    'powering off' => [fn (): array => ['last_seen' => now(), 'power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now('Europe/London')->setTime(17, 30)->utc()], 'Off since 17:30', 'amber'],
    'powering on' => [fn (): array => ['last_seen' => now(), 'power_state' => DevicePowerState::PoweringOn, 'power_state_changed_at' => now()], 'Powering on', 'sky'],
    'powered on then went quiet' => [fn (): array => ['last_seen' => now()->subHour(), 'power_state' => DevicePowerState::PoweringOn, 'power_state_changed_at' => now()->subHour()], 'Offline', 'red'],
]);

it('lets a powering off device be woken even inside the online window', function (): void {
    $device = Device::factory()->active()->poweringOff()->create(['last_seen' => now(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

    expect($device->isWakeable)->toBeTrue();

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->assertSeeHtml('wire:click="wake"');
});

it('shows the power state badges on the device list', function (): void {
    Device::factory()->active()->poweringOff()->create(['last_seen' => now()]);
    Device::factory()->active()->poweringOn()->create();

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee('Off since')
        ->assertSee('Powering on');
});

it('shows the power state badge on the device page and flips it when the device wakes', function (): void {
    $device = Device::factory()->active()->poweringOff()->create(['last_seen' => now()]);

    $page = Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->assertSee('Off since');

    app(RecordDevicePowerEvent::class)($device->fresh(), DevicePowerState::PoweringOn, PowerEventReason::Resume);

    $page->dispatch("echo-private:devices.{$device->id},DeviceUpdated", ['deviceId' => $device->id, 'status' => 'active'])
        ->assertSee('Powering on')
        ->assertDontSee('Powering off');
});

describe('offline alerts', function (): void {
    beforeEach(function (): void {
        AlertRule::factory()->offline()->create(['operator' => AlertOperator::GreaterThan, 'threshold' => 10]);
    });

    it('does not raise an offline alert for a device that announced powering off', function (): void {
        Device::factory()->active()->poweringOff()->create(['last_seen' => now()->subHour()]);

        $this->artisan('devices:check-offline')->assertSuccessful();

        expect(Alert::count())->toBe(0);
    });

    it('still raises it for a device that went quiet without a word', function (): void {
        Device::factory()->active()->create(['last_seen' => now()->subHour()]);

        $this->artisan('devices:check-offline')->assertSuccessful();

        expect(Alert::count())->toBe(1);
    });

    it('raises it once a device that powered on goes quiet again', function (): void {
        $device = Device::factory()->active()->poweringOn()->create();
        $this->travel(1)->hour();

        (new EvaluateAlertRules)($device->fresh(), new DeviceMetric);

        expect(Alert::count())->toBe(1);
    });
});

describe('offline announcements', function (): void {
    it('skips devices that already announced powering off', function (): void {
        Event::fake([DeviceUpdated::class]);
        Device::factory()->active()->poweringOff()->create(['last_seen' => now()->subMinutes(5)->subSeconds(30)]);

        $this->artisan('devices:check-offline')->assertSuccessful();

        Event::assertNotDispatched(DeviceUpdated::class);
    });

    it('still announces a powered on device that went quiet', function (): void {
        Event::fake([DeviceUpdated::class]);
        $device = Device::factory()->active()->create([
            'last_seen' => now()->subMinutes(5)->subSeconds(30),
            'power_state' => DevicePowerState::PoweringOn,
            'power_state_changed_at' => now()->subMinutes(6),
        ]);

        $this->artisan('devices:check-offline')->assertSuccessful();

        Event::assertDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => $event->deviceId === $device->id);
    });
});

it('stops showing Powering on once the hold passes even without another check-in', function (): void {
    $device = Device::factory()->active()->create([
        'last_seen' => now(),
        'power_state' => DevicePowerState::PoweringOn,
        'power_state_changed_at' => now(),
    ]);

    expect($device->statusLabel())->toBe('Powering on');

    $this->travel(config('devices.power.powering_on_hold_seconds') + 1)->seconds();

    expect($device->fresh()->statusLabel())->toBe('Online');
});

it('settles an expired Powering on every minute and broadcasts it', function (): void {
    $expired = Device::factory()->active()->create(['power_state' => DevicePowerState::PoweringOn, 'power_state_changed_at' => now()->subMinutes(2)]);
    $fresh = Device::factory()->active()->create(['power_state' => DevicePowerState::PoweringOn, 'power_state_changed_at' => now()]);
    $asleep = Device::factory()->active()->create(['power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()->subHour()]);
    Event::fake([DeviceUpdated::class]);

    $this->artisan('devices:check-offline')->assertSuccessful();

    expect($expired->fresh()->power_state)->toBeNull()
        ->and($fresh->fresh()->power_state)->toBe(DevicePowerState::PoweringOn)
        ->and($asleep->fresh()->power_state)->toBe(DevicePowerState::PoweringOff);
    Event::assertDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => $event->deviceId === $expired->id);
});

describe('a powering off notice that never ends in a wake', function (): void {
    it('lapses after the max window and falls back to Offline', function (): void {
        $device = Device::factory()->active()->create([
            'last_seen' => now()->subHours(config('devices.power.powering_off_max_hours') + 1),
            'power_state' => DevicePowerState::PoweringOff,
            'power_state_changed_at' => now()->subHours(config('devices.power.powering_off_max_hours') + 1),
        ]);

        expect($device->isPoweringOff)->toBeFalse()
            ->and($device->statusLabel())->toBe('Offline')
            ->and($device->statusColor())->toBe('red');
    });

    it('holds for a whole weekend', function (): void {
        $device = Device::factory()->active()->create([
            'last_seen' => now()->subHours(60),
            'power_state' => DevicePowerState::PoweringOff,
            'power_state_changed_at' => now()->subHours(60),
        ]);

        expect($device->isPoweringOff)->toBeTrue()
            ->and($device->statusLabel())->toStartWith('Off since');
    });

    it('is settled by the every-minute job and broadcast', function (): void {
        $lapsed = Device::factory()->active()->create(['power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()->subHours(config('devices.power.powering_off_max_hours'))->subMinute()]);
        $asleep = Device::factory()->active()->create(['power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()->subHours(config('devices.power.powering_off_max_hours'))->addMinute()]);
        Event::fake([DeviceUpdated::class]);

        $this->artisan('devices:check-offline')->assertSuccessful();

        expect($lapsed->fresh()->power_state)->toBeNull()
            ->and($lapsed->fresh()->power_state_changed_at)->toBeNull()
            ->and($asleep->fresh()->power_state)->toBe(DevicePowerState::PoweringOff);
        Event::assertDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => $event->deviceId === $lapsed->id);
        Event::assertNotDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => $event->deviceId === $asleep->id);
    });

    it('raises an offline alert again once lapsed', function (): void {
        AlertRule::factory()->offline()->create(['operator' => AlertOperator::GreaterThan, 'threshold' => 10]);
        Device::factory()->active()->create([
            'last_seen' => now()->subHours(config('devices.power.powering_off_max_hours') + 1),
            'power_state' => DevicePowerState::PoweringOff,
            'power_state_changed_at' => now()->subHours(config('devices.power.powering_off_max_hours') + 1),
        ]);

        $this->artisan('devices:check-offline')->assertSuccessful();

        expect(Alert::count())->toBe(1);
    });

    it('takes the window from config', function (): void {
        config(['devices.power.powering_off_max_hours' => 1]);
        $device = Device::factory()->active()->create(['power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()->subHours(2)]);

        expect($device->isPoweringOff)->toBeFalse();
    });
});

it('agrees between the model and the query on which devices are powering off', function (array $attributes, bool $isPoweringOff): void {
    $device = Device::factory()->active()->create($attributes);

    expect($device->isPoweringOff)->toBe($isPoweringOff)
        ->and(Device::query()->notPoweringOff()->whereKey($device->id)->exists())->toBe(! $isPoweringOff)
        ->and(Device::query()->withLapsedPowerState()->whereKey($device->id)->exists())->toBe($attributes['power_state'] !== null && ! $isPoweringOff);
})->with([
    'no power state' => [['power_state' => null, 'power_state_changed_at' => null], false],
    'just powered off' => [['power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()], true],
    'powered off past the window' => [['power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()->subDays(4)], false],
    'powered off with no time' => [['power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => null], false],
]);

it('agrees between the model and the query on which devices are holding powering on', function (array $attributes, bool $isHolding): void {
    $device = Device::factory()->active()->create(['last_seen' => now(), ...$attributes]);

    expect($device->hasPowerStateInForce(DevicePowerState::PoweringOn))->toBe($isHolding)
        ->and($device->isPoweringOn)->toBe($isHolding)
        ->and(Device::query()->withLapsedPowerState()->whereKey($device->id)->exists())->toBe(! $isHolding);
})->with([
    'within the hold' => [fn (): array => ['power_state' => DevicePowerState::PoweringOn, 'power_state_changed_at' => now()], true],
    'past the hold' => [fn (): array => ['power_state' => DevicePowerState::PoweringOn, 'power_state_changed_at' => now()->subMinute()], false],
    'powered on with no time' => [['power_state' => DevicePowerState::PoweringOn, 'power_state_changed_at' => null], false],
]);

it('says when a device went off in UK time, summer and winter, while storing UTC', function (string $storedUtc, string $label): void {
    $this->travelTo(Illuminate\Support\Carbon::parse($storedUtc, 'UTC')->addMinutes(5));
    $device = Device::factory()->active()->create([
        'last_seen' => Illuminate\Support\Carbon::parse($storedUtc, 'UTC'),
        'power_state' => DevicePowerState::PoweringOff,
        'power_state_changed_at' => Illuminate\Support\Carbon::parse($storedUtc, 'UTC'),
    ]);

    expect($device->statusLabel())->toBe($label)
        ->and($device->getRawOriginal('power_state_changed_at'))->toBe($storedUtc);
})->with([
    'BST, the morning this was found' => ['2026-10-06 09:42:10', 'Off since 10:42'],
    'GMT, after the clocks go back' => ['2026-12-01 09:42:10', 'Off since 09:42'],
]);
