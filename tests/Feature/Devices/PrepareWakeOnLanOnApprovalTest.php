<?php

declare(strict_types=1);

use App\Enums\CommandStatus;
use App\Enums\DeviceStatus;
use App\Livewire\Devices\Pending;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->prepareScript = Script::factory()->system()->create(['slug' => 'prepare-wake-on-lan']);
});

it('queues Prepare Wake-on-LAN on a Windows device the moment it is approved', function (): void {
    $device = Device::factory()->windows()->create(['status' => DeviceStatus::Pending]);

    Livewire::actingAs($this->user)->test(Pending::class)->call('approve', $device->id);

    $command = DeviceCommand::query()->where('device_id', $device->id)->sole();
    expect($command->script_id)->toBe($this->prepareScript->id)
        ->and($command->status)->toBe(CommandStatus::Pending)
        ->and($command->queued_by)->toBe($this->user->id);
});

it('does not queue it on a Linux device', function (): void {
    $device = Device::factory()->linux()->create(['status' => DeviceStatus::Pending]);

    Livewire::actingAs($this->user)->test(Pending::class)->call('approve', $device->id);

    expect(DeviceCommand::query()->where('device_id', $device->id)->exists())->toBeFalse();
});

it('still approves when the built-in script is missing', function (): void {
    $this->prepareScript->delete();
    $device = Device::factory()->windows()->create(['status' => DeviceStatus::Pending]);

    Livewire::actingAs($this->user)->test(Pending::class)->call('approve', $device->id)->assertHasNoErrors();

    expect($device->fresh()->status)->toBe(DeviceStatus::Active)
        ->and(DeviceCommand::query()->exists())->toBeFalse();
});

it('ignores a user-made script that shares the slug', function (): void {
    $this->prepareScript->delete();
    Script::factory()->create(['slug' => 'prepare-wake-on-lan']);
    $device = Device::factory()->windows()->create(['status' => DeviceStatus::Pending]);

    Livewire::actingAs($this->user)->test(Pending::class)->call('approve', $device->id);

    expect(DeviceCommand::query()->exists())->toBeFalse();
});

it('does not queue anything when approval is refused', function (): void {
    $device = Device::factory()->windows()->active()->create();

    Livewire::actingAs($this->user)->test(Pending::class)->call('approve', $device->id);

    expect(DeviceCommand::query()->exists())->toBeFalse();
});
