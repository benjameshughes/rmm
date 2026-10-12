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

it('shows each adapter with its address, gateway and DNS servers', function (): void {
    inventoried($this->device);

    Livewire::actingAs($this->user)->test(System::class, ['device' => $this->device])
        ->assertSeeInOrder(['Up · 1 Gbps', '192.168.10.57/24 · DHCP', '192.168.10.1', '192.168.10.1, 1.1.1.1'])
        ->assertSeeInOrder(['Intel(R) Iris(R) Xe Graphics', '1920x1080', 'OK']);
});

it('shows static addressing and leaves the network settings blank for an adapter read through the CIM fallback', function (): void {
    inventoried($this->device, ['data' => ['network_adapters' => [
        ['name' => 'Ethernet', 'ipv4_addresses' => ['10.0.0.5/24', '10.0.0.6/24'], 'default_gateway' => '10.0.0.1', 'dns_servers' => ['10.0.0.1'], 'is_dhcp' => false],
        ['name' => 'Ethernet 2', 'ipv4_addresses' => null, 'default_gateway' => null, 'dns_servers' => null, 'is_dhcp' => null],
    ]]]);

    $adapters = $this->device->fresh()->latestInventory->snapshot()->hardware()->networkAdapters();

    expect($adapters->columns)->toBe(['Name', 'Description', 'MAC', 'Link', 'IPv4', 'Gateway', 'DNS', 'Driver'])
        ->and($adapters->rows)->toBe([
            ['Ethernet', null, null, null, '10.0.0.5/24, 10.0.0.6/24 · Static', '10.0.0.1', '10.0.0.1', null],
            ['Ethernet 2', null, null, null, null, null, null, null],
        ]);
});

it('makes devices Windows says are not working stand out, amber when only disabled', function (): void {
    inventoried($this->device);

    Livewire::actingAs($this->user)->test(System::class, ['device' => $this->device])
        ->assertSeeInOrder(['Device problems', 'Realtek USB Audio · MEDIA', 'Cannot start · Code 10 · Error']);

    expect($this->device->fresh()->latestInventory->snapshot()->hardware()->deviceProblems()->mapWithKeys(fn ($check) => [$check->value => $check->color])->all())->toBe([
        'Disabled · Code 22 · Error' => 'amber',
        'Cannot start · Code 10 · Error' => 'red',
    ]);
});

it('says so plainly when no device has a problem', function (): void {
    inventoried($this->device, ['data' => ['device_problems' => []]]);

    Livewire::actingAs($this->user)->test(System::class, ['device' => $this->device])
        ->assertSeeInOrder(['Device problems', 'Present devices', 'No problems']);

    expect($this->device->fresh()->latestInventory->snapshot()->hardware()->deviceProblems()->sole()->color)->toBe('green');
});

it('lists the listening ports and the recent system errors in London time', function (): void {
    inventoried($this->device, ['data' => [
        'listening_ports' => [['address' => '0.0.0.0', 'port' => 445, 'process_id' => 4, 'process_name' => 'System']],
        'recent_errors' => [['time' => '2026-10-05T08:02:11.0000000Z', 'provider' => 'Service Control Manager', 'event_id' => 7031, 'level' => 'Error', 'message' => 'The Dell TechHub service terminated unexpectedly.']],
    ]]);

    Livewire::actingAs($this->user)->test(System::class, ['device' => $this->device])
        ->assertSeeInOrder(['Listening ports (1)', '445', '0.0.0.0', 'System', '4'])
        ->assertSeeInOrder(['Recent system errors (1)', '5 Oct 2026, 09:02', 'Error', 'Service Control Manager', '7031', 'The Dell TechHub service terminated unexpectedly.']);
});

it('copes with an inventory where most sections are missing', function (): void {
    inventoried($this->device, ['data' => ['windows' => ['caption' => 'Microsoft Windows 10 Pro'], 'security' => ['bitlocker' => 'garbage']]]);

    Livewire::actingAs($this->user)->test(System::class, ['device' => $this->device])
        ->assertSee('Microsoft Windows 10 Pro')
        ->assertSee('BitLocker is not available on this edition of Windows.')
        ->assertSee('The Administrators group could not be read.')
        ->assertSee('No battery.')
        ->assertSee('Not reported. Run the inventory again to check.')
        ->assertDontSee('No problems')
        ->assertSee('Listening ports (0)')
        ->assertSee('Recent system errors (0)');
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
