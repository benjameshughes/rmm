<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Livewire\Devices\System;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceInventory;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['hostname' => 'OFFICE-PC', 'agent_version' => '0.7.1', 'system_inventoried_at' => now()->subHours(2)]);
});

function inventoried(Device $device, array $attributes = []): DeviceInventory
{
    return DeviceInventory::factory()->create(['device_id' => $device->id, 'collected_at' => now()->subHours(2), ...$attributes]);
}

it('shows the latest inventory from the fixture', function (): void {
    inventoried($this->device);

    Livewire::actingAs($this->user)->test(System::class, ['device' => $this->device])
        ->assertSee('Collected 2 hours ago.')
        ->assertSee('Run now')
        ->assertSeeInOrder(['Service tag', '7HQ2KZ3'])
        ->assertSeeInOrder(['RAM', '16.0 GB'])
        ->assertSee('Latitude 5440')
        ->assertSee('Microsoft Windows 11 Pro 23H2')
        ->assertSee('22631.4317')
        ->assertSeeInOrder(['C: (system)', 'On · Fully encrypted'])
        ->assertSeeInOrder(['Secure Boot', 'On'])
        ->assertSee('OFFICE-PC\itadmin')
        ->assertSee('CN0V1RXJ-FCC00-2BJ-3T1L')
        ->assertSee('Restart pending')
        ->assertSee('KB5043076')
        ->assertSeeInOrder(['Drivers (2)', 'Realtek Audio'])
        ->assertSee('Dell TechHub')
        ->assertSee('GoogleUpdateTaskMachineCore')
        ->assertDontSeeHtml('data-system-empty');
});

it('colours security settings by how safe they are', function (): void {
    inventoried($this->device, ['data' => [
        'security' => [
            'bitlocker' => [['drive' => 'C:', 'is_system_drive' => true, 'protection_status' => 'Off', 'conversion_status' => 'Fully decrypted']],
            'secure_boot' => null,
            'defender' => ['is_real_time_enabled' => true, 'signature_updated_at' => now()->subDays(10)->toIso8601String(), 'signature_version' => '1.400.0.0'],
            'uac' => ['is_enabled' => true, 'consent_prompt_admin' => 0],
        ],
    ]]);

    $security = $this->device->fresh()->latestInventory->snapshot()->security();

    $systemDrive = $security->bitlocker()->sole();

    expect($systemDrive->value)->toBe('Off · Fully decrypted')
        ->and($systemDrive->color)->toBe('red')
        ->and($security->checks()->mapWithKeys(fn ($check) => [$check->label => $check->color])->all())->toBe([
            'Secure Boot' => 'zinc',
            'TPM' => 'red',
            'Defender' => 'green',
            'Defender signatures' => 'red',
            'UAC' => 'amber',
        ]);
});

it('copes with an inventory where most sections are missing', function (): void {
    inventoried($this->device, ['data' => ['windows' => ['caption' => 'Microsoft Windows 10 Pro'], 'security' => ['bitlocker' => 'garbage']]]);

    Livewire::actingAs($this->user)->test(System::class, ['device' => $this->device])
        ->assertSee('Microsoft Windows 10 Pro')
        ->assertSee('BitLocker is not available on this edition of Windows.')
        ->assertSee('The Administrators group could not be read.')
        ->assertSee('No battery.');
});

it('explains the missing inventory and runs it on request', function (): void {
    $this->device->forceFill(['system_inventoried_at' => null])->save();

    Livewire::actingAs($this->user)->test(System::class, ['device' => $this->device])
        ->assertSeeHtml('data-system-empty')
        ->assertSee('Run now')
        ->call('refreshInventory')
        ->assertDispatched('command-queued')
        ->assertSee('Queued...')
        ->assertSeeHtml('data-run-button-busy');

    expect($this->device->commands()->sole()->script_id)->toBe(Script::findSystem('system-inventory')->id);
});

it('does not queue a second inventory while one is waiting', function (): void {
    inventoried($this->device);
    $system = Livewire::actingAs($this->user)->test(System::class, ['device' => $this->device]);

    $system->call('refreshInventory');
    $system->call('refreshInventory')->assertSee('Queued...');

    expect($this->device->commands()->count())->toBe(1);
});

it('hides Run now from users who may not run commands and refuses them', function (): void {
    inventoried($this->device);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'runCommands' ? false : null);

    Livewire::actingAs($this->user)->test(System::class, ['device' => $this->device])
        ->assertDontSeeHtml('wire:click="refreshInventory"')
        ->call('refreshInventory')->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('shows the not supported note on monitor-only and linux devices and never queues', function (string $state): void {
    $device = Device::factory()->active()->{$state}()->create();
    inventoried($device);

    Livewire::actingAs($this->user)->test(System::class, ['device' => $device])
        ->assertSee('only collected from Windows devices that accept commands')
        ->assertDontSee('7HQ2KZ3')
        ->call('refreshInventory');

    expect(DeviceCommand::query()->count())->toBe(0);
})->with(['monitorOnly', 'linux']);

it('refreshes when a new inventory lands', function (): void {
    $system = Livewire::actingAs($this->user)->test(System::class, ['device' => $this->device])
        ->assertSeeHtml('data-system-empty');

    inventoried($this->device);

    $system->dispatch("echo-private:devices.{$this->device->id},SystemInventorySynced", ['deviceId' => $this->device->id])
        ->assertSee('7HQ2KZ3');
});

it('ties the Run now button to the inventory command, from queued to running and back', function (): void {
    inventoried($this->device);
    $system = Livewire::actingAs($this->user)->test(System::class, ['device' => $this->device])
        ->assertDontSeeHtml('data-run-button-busy');

    $system->call('refreshInventory')->assertSee('Queued...');

    $command = $this->device->commands()->sole();
    $command->markAsSent();
    $command->markAsRunning();

    $system->dispatch("echo-private:devices.{$this->device->id},CommandUpdated", ['commandId' => $command->id])
        ->assertSee('Running...')
        ->assertSeeHtml("commandId: {$command->id}");

    $command->markAsCompleted('{}', 0);

    $system->dispatch("echo-private:devices.{$this->device->id},CommandUpdated", ['commandId' => $command->id])
        ->assertDontSeeHtml('data-run-button-busy')
        ->assertSee('Run now');
});
