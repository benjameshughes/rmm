<?php

declare(strict_types=1);

use App\Enums\CommandStatus;
use App\Enums\DevicePowerState;
use App\Livewire\Devices\Commands;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['last_seen' => now()]);
});

it('says a queued command is waiting for the device to wake when it is not online', function (array $attributes): void {
    $this->device->forceFill($attributes)->save();
    DeviceCommand::factory()->create(['device_id' => $this->device->id, 'status' => CommandStatus::Pending]);

    Livewire::actingAs($this->user)->test(Commands::class, ['device' => $this->device->fresh()])
        ->assertSeeHtml('data-waiting-for-wake')
        ->assertSee('waiting for the device to wake');
})->with([
    'offline' => [fn (): array => ['last_seen' => now()->subHour()]],
    'powering off' => [fn (): array => ['power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()]],
]);

it('does not say waiting for wake when the device is online or the command is not queued', function (CommandStatus $status, array $attributes): void {
    $this->device->forceFill($attributes)->save();
    DeviceCommand::factory()->create(['device_id' => $this->device->id, 'status' => $status]);

    Livewire::actingAs($this->user)->test(Commands::class, ['device' => $this->device->fresh()])
        ->assertDontSeeHtml('data-waiting-for-wake');
})->with([
    'queued on an online device' => [CommandStatus::Pending, []],
    'finished on an offline device' => [CommandStatus::Completed, ['last_seen' => now()->subHour()]],
]);
