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
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->netdataScript = Script::factory()->system()->create(['slug' => 'install-netdata']);
});

it('queues Install Netdata on a Windows device the moment it is approved', function (): void {
    $device = Device::factory()->windows()->create(['status' => DeviceStatus::Pending]);

    Livewire::actingAs($this->user)->test(Pending::class)->call('approve', $device->id);

    $command = DeviceCommand::query()->where('device_id', $device->id)->sole();
    expect($command->script_id)->toBe($this->netdataScript->id)
        ->and($command->status)->toBe(CommandStatus::Pending)
        ->and($command->queued_by)->toBe($this->user->id);
});

it('queues it alongside Prepare Wake-on-LAN', function (): void {
    $prepareScript = Script::factory()->system()->create(['slug' => 'prepare-wake-on-lan']);
    $device = Device::factory()->windows()->create(['status' => DeviceStatus::Pending]);

    Livewire::actingAs($this->user)->test(Pending::class)->call('approve', $device->id);

    expect(DeviceCommand::query()->where('device_id', $device->id)->pluck('script_id')->all())
        ->toEqualCanonicalizing([$this->netdataScript->id, $prepareScript->id]);
});

it('does not queue it on a Linux device', function (): void {
    $device = Device::factory()->linux()->create(['status' => DeviceStatus::Pending]);

    Livewire::actingAs($this->user)->test(Pending::class)->call('approve', $device->id);

    expect(DeviceCommand::query()->where('device_id', $device->id)->exists())->toBeFalse();
});

it('still approves when the built-in script is missing', function (): void {
    $this->netdataScript->delete();
    $device = Device::factory()->windows()->create(['status' => DeviceStatus::Pending]);

    Livewire::actingAs($this->user)->test(Pending::class)->call('approve', $device->id)->assertHasNoErrors();

    expect($device->fresh()->status)->toBe(DeviceStatus::Active)
        ->and(DeviceCommand::query()->exists())->toBeFalse();
});

it('ignores a user-made script that shares the slug', function (): void {
    $this->netdataScript->delete();
    Script::factory()->create(['slug' => 'install-netdata']);
    $device = Device::factory()->windows()->create(['status' => DeviceStatus::Pending]);

    Livewire::actingAs($this->user)->test(Pending::class)->call('approve', $device->id);

    expect(DeviceCommand::query()->exists())->toBeFalse();
});

it('ships the install script as a Windows system script', function (): void {
    expect(config('scripts.system.install-netdata'))
        ->platform->toBe('windows')
        ->requires_admin->toBeTrue()
        ->and(resource_path('scripts/'.config('scripts.system.install-netdata.file')))->toBeFile();
});

it('keeps windows netdata on localhost with anonymous statistics off, restarting only on a config change', function (): void {
    $script = File::get(resource_path('scripts/'.config('scripts.system.install-netdata.file')));

    expect($script)
        ->toContain('$env:ProgramFiles\Netdata\etc\netdata')
        ->toContain("'[web]'")
        ->toContain('bind to = 127.0.0.1')
        ->toContain('.opt-out-from-anonymous-statistics')
        ->toContain('if ($config -ne $current)')
        ->toContain("Restart-Service -Name 'netdata'")
        ->not->toContain('try {')
        ->not->toContain('claim');

    expect(strpos($script, 'Restart-Service'))->toBeGreaterThan(strpos($script, 'if ($config -ne $current)'))
        ->and(strpos($script, 'bind to = 127.0.0.1'))->toBeGreaterThan(strpos($script, 'if ($install.ExitCode -ne 0)'));
});
