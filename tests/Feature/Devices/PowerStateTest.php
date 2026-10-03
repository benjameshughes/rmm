<?php

declare(strict_types=1);

use App\Actions\Alert\EvaluateAlertRules;
use App\Actions\Device\RecordDevicePowerEvent;
use App\Enums\AlertOperator;
use App\Enums\DevicePowerState;
use App\Enums\PowerEventReason;
use App\Events\DeviceUpdated;
use App\Livewire\Devices\Index;
use App\Livewire\Devices\Show;
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
    'powering off' => [['last_seen' => now(), 'power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()->setTime(17, 30)], 'Powering off since 17:30', 'amber'],
    'powering on' => [['last_seen' => now(), 'power_state' => DevicePowerState::PoweringOn, 'power_state_changed_at' => now()], 'Powering on', 'sky'],
    'powered on then went quiet' => [['last_seen' => now()->subHour(), 'power_state' => DevicePowerState::PoweringOn, 'power_state_changed_at' => now()->subHour()], 'Offline', 'red'],
]);

it('lets a powering off device be woken even inside the online window', function (): void {
    $device = Device::factory()->active()->poweringOff()->create(['last_seen' => now(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

    expect($device->isWakeable)->toBeTrue();

    Livewire::actingAs($this->user)->test(Show::class, ['device' => $device])
        ->assertSeeHtml('wire:click="wake"');
});

it('shows the power state badges on the device list', function (): void {
    Device::factory()->active()->poweringOff()->create(['last_seen' => now()]);
    Device::factory()->active()->poweringOn()->create();

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee('Powering off since')
        ->assertSee('Powering on');
});

it('shows the power state badge on the device page and flips it when the device wakes', function (): void {
    $device = Device::factory()->active()->poweringOff()->create(['last_seen' => now()]);

    $page = Livewire::actingAs($this->user)->test(Show::class, ['device' => $device])
        ->assertSee('Powering off since');

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
