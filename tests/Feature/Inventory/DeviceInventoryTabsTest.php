<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Livewire\Devices\Apps;
use App\Livewire\Devices\Details;
use App\Livewire\Devices\Overview;
use App\Livewire\Devices\Security;
use App\Livewire\Devices\SystemInventory;
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

it('spreads the latest inventory across the tabs it belongs on', function (): void {
    inventoried($this->device);

    Livewire::actingAs($this->user)->test(Details::class, ['device' => $this->device])
        ->assertSee('System inventory · Collected 2 hours ago.')
        ->assertSee('Run now')
        ->assertSeeInOrder(['This PC', 'Service tag', '7HQ2KZ3'])
        ->assertSeeInOrder(['RAM', '16.0 GB'])
        ->assertSee('Latitude 5440')
        ->assertSee('Microsoft Windows 11 Pro 23H2')
        ->assertSee('22631.4317')
        ->assertSee('OFFICE-PC\itadmin')
        ->assertSee('CN0V1RXJ-FCC00-2BJ-3T1L')
        ->assertDontSee('System Information');

    Livewire::actingAs($this->user)->test(Security::class, ['device' => $this->device])
        ->assertSeeInOrder(['C: (system)', 'On · Fully encrypted'])
        ->assertSeeInOrder(['Secure Boot', 'On'])
        ->assertDontSee('7HQ2KZ3');

    Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device])
        ->assertSeeInOrder(['Installed updates', 'KB5043076'])
        ->assertSeeInOrder(['Drivers (2)', 'Realtek Audio'])
        ->assertSee('Dell TechHub')
        ->assertSee('GoogleUpdateTaskMachineCore');

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeInOrder(['Restart', 'Pending'])
        ->assertDontSee('KB5043076');
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

    Livewire::actingAs($this->user)->test(Details::class, ['device' => $this->device])
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

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeInOrder(['Device problems', 'Realtek USB Audio · MEDIA', 'Cannot start · Code 10 · Error']);

    expect($this->device->fresh()->latestInventory->snapshot()->hardware()->deviceProblems()->mapWithKeys(fn ($check) => [$check->value => $check->color])->all())->toBe([
        'Disabled · Code 22 · Error' => 'amber',
        'Cannot start · Code 10 · Error' => 'red',
    ]);
});

it('says so plainly when no device has a problem', function (): void {
    inventoried($this->device, ['data' => ['device_problems' => []]]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeInOrder(['Device problems', 'None']);

    expect($this->device->fresh()->latestInventory->snapshot()->hardware()->deviceProblems()->sole()->color)->toBe('green');
});

it('lists the listening ports and the recent system errors in London time', function (): void {
    inventoried($this->device, ['data' => [
        'listening_ports' => [['address' => '0.0.0.0', 'port' => 445, 'process_id' => 4, 'process_name' => 'System']],
        'recent_errors' => [['time' => '2026-10-05T08:02:11.0000000Z', 'provider' => 'Service Control Manager', 'event_id' => 7031, 'level' => 'Error', 'message' => 'The Dell TechHub service terminated unexpectedly.']],
    ]]);

    Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device])
        ->assertSeeInOrder(['Listening ports (1)', '445', '0.0.0.0', 'System', '4']);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('data-system-errors')
        ->assertSeeInOrder(['System errors', 'Error', '5 Oct 2026, 09:02', 'Service Control Manager', 'Event 7031', 'The Dell TechHub service terminated unexpectedly.']);
});

it('puts what needs attention on the Overview, tiles first, then the errors and the broken devices', function (): void {
    inventoried($this->device);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('data-system-status')
        ->assertSeeInOrder(['System errors', 'Device problems', 'Security', 'Restart', 'The system has rebooted without cleanly shutting down first.', 'Realtek USB Audio']);
});

it('colours the system errors tile by the worst event and caps the count at the script limit', function (array $errors, string $value, string $color): void {
    inventoried($this->device, ['data' => ['recent_errors' => $errors]]);

    $check = $this->device->fresh()->latestInventory->snapshot()->software()->systemErrorsCheck();

    expect([$check->value, $check->color])->toBe([$value, $color]);
})->with([
    'none' => [[], 'None', 'green'],
    'errors only' => [[['level' => 'Error'], ['level' => 'Error']], '2', 'amber'],
    'a critical' => [[['level' => 'Error'], ['level' => 'Critical']], '2', 'red'],
    'at the limit' => [array_fill(0, 25, ['level' => 'Error']), '25+', 'amber'],
]);

it('says the System log is clean when there are no errors', function (): void {
    inventoried($this->device, ['data' => ['recent_errors' => []]]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('data-system-errors-clear')
        ->assertSee('No errors. The System log is clean.');
});

it('clamps long error messages until clicked and leaves short ones alone', function (): void {
    inventoried($this->device, ['data' => ['recent_errors' => [
        ['level' => 'Error', 'message' => str_repeat('Long message. ', 20)],
        ['level' => 'Error', 'message' => 'Short.'],
    ]]]);

    $events = $this->device->fresh()->latestInventory->snapshot()->software()->systemErrors();

    expect($events->map->hasLongMessage()->all())->toBe([true, false]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('line-clamp-1')
        ->assertSee('Show more');
});

it('summarises device problems and security for the status tiles', function (): void {
    inventoried($this->device);
    $snapshot = $this->device->fresh()->latestInventory->snapshot();

    expect([$snapshot->hardware()->deviceProblemsCheck()->value, $snapshot->hardware()->deviceProblemsCheck()->color])->toBe(['2', 'red']);

    inventoried($this->device, ['collected_at' => now(), 'data' => ['device_problems' => [], 'security' => [
        'bitlocker' => [['drive' => 'C:', 'is_system_drive' => true, 'protection_status' => 'On']],
        'secure_boot' => true,
        'tpm' => ['is_present' => true, 'is_enabled' => true, 'is_activated' => true],
        'defender' => ['is_real_time_enabled' => true, 'signature_updated_at' => now()->subDays(4)->toIso8601String()],
        'uac' => ['is_enabled' => true, 'consent_prompt_admin' => 5],
        'firewall' => [['name' => 'Domain', 'is_enabled' => true]],
    ]]]);
    $snapshot = $this->device->fresh()->latestInventory->snapshot();

    expect($snapshot->hardware()->deviceProblemsCheck()->value)->toBe('None')
        ->and([$snapshot->security()->postureCheck()->value, $snapshot->security()->postureCheck()->color])->toBe(['1 to check', 'amber']);
});

it('copes with an inventory where most sections are missing', function (): void {
    inventoried($this->device, ['data' => ['windows' => ['caption' => 'Microsoft Windows 10 Pro'], 'security' => ['bitlocker' => 'garbage']]]);

    Livewire::actingAs($this->user)->test(Details::class, ['device' => $this->device])
        ->assertSee('Microsoft Windows 10 Pro')
        ->assertSee('The Administrators group could not be read.')
        ->assertSee('No battery.');

    Livewire::actingAs($this->user)->test(Security::class, ['device' => $this->device])
        ->assertSee('BitLocker is not available on this edition of Windows.');

    Livewire::actingAs($this->user)->test(Apps::class, ['device' => $this->device])
        ->assertSee('Listening ports (0)');

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeInOrder(['System errors', 'Not reported', 'Device problems', 'Not reported'])
        ->assertSee('Not reported. Run the inventory again to check.');
});

it('explains the missing inventory and runs it on request', function (): void {
    $this->device->forceFill(['system_inventoried_at' => null])->save();

    Livewire::actingAs($this->user)->test(Security::class, ['device' => $this->device])
        ->assertSeeHtml('data-system-empty');

    Livewire::actingAs($this->user)->test(SystemInventory::class, ['device' => $this->device])
        ->assertSee('Not collected yet.')
        ->assertSee('Run now')
        ->call('refreshInventory')
        ->assertDispatched('command-queued')
        ->assertSee('Queued...')
        ->assertSeeHtml('data-run-button-busy');

    expect($this->device->commands()->sole()->script_id)->toBe(Script::findSystem('system-inventory')->id);
});

it('does not queue a second inventory while one is waiting', function (): void {
    inventoried($this->device);
    $system = Livewire::actingAs($this->user)->test(SystemInventory::class, ['device' => $this->device]);

    $system->call('refreshInventory');
    $system->call('refreshInventory')->assertSee('Queued...');

    expect($this->device->commands()->count())->toBe(1);
});

it('hides Run now from users who may not run commands and refuses them', function (): void {
    inventoried($this->device);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'runCommands' ? false : null);

    Livewire::actingAs($this->user)->test(SystemInventory::class, ['device' => $this->device])
        ->assertDontSeeHtml('wire:click="refreshInventory"')
        ->call('refreshInventory')->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('shows the not supported note on monitor-only and linux devices and never queues', function (string $state): void {
    $device = Device::factory()->active()->{$state}()->create();
    inventoried($device);

    Livewire::actingAs($this->user)->test(Security::class, ['device' => $device])
        ->assertDontSee('only collected from Windows devices that accept commands')
        ->assertSee('No security report yet')
        ->assertDontSee('C: (system)');

    Livewire::actingAs($this->user)->test(Details::class, ['device' => $device])
        ->assertDontSeeHtml('data-system-inventory-control')
        ->assertDontSee('7HQ2KZ3');

    Livewire::actingAs($this->user)->test(SystemInventory::class, ['device' => $device])
        ->call('refreshInventory');

    expect(DeviceCommand::query()->count())->toBe(0);
})->with(['monitorOnly', 'linux']);

it('refreshes when a new inventory lands', function (): void {
    $system = Livewire::actingAs($this->user)->test(Details::class, ['device' => $this->device])
        ->assertDontSee('7HQ2KZ3');

    inventoried($this->device);

    $system->dispatch("echo-private:devices.{$this->device->id},SystemInventorySynced", ['deviceId' => $this->device->id])
        ->assertSee('7HQ2KZ3');
});

it('ties the Run now button to the inventory command, from queued to running and back', function (): void {
    inventoried($this->device);
    $system = Livewire::actingAs($this->user)->test(SystemInventory::class, ['device' => $this->device])
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
