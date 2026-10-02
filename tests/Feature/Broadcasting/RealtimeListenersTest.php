<?php

declare(strict_types=1);

use App\Enums\CommandStatus;
use App\Enums\DeviceStatus;
use App\Livewire\AlertBell;
use App\Livewire\Alerts\Index as AlertsIndex;
use App\Livewire\Commands\Detail;
use App\Livewire\Devices\Index as DevicesIndex;
use App\Livewire\Devices\Pending;
use App\Livewire\Devices\Show;
use App\Livewire\Scripts\Show as ScriptsShow;
use App\Models\Alert;
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
});

it('adds a newly enrolled device to the device list', function (): void {
    $component = Livewire::actingAs($this->user)->test(DevicesIndex::class)
        ->assertDontSee('LATE-ENROLLER');

    $device = Device::factory()->create(['hostname' => 'LATE-ENROLLER']);

    $component->dispatch('echo-private:devices,DeviceEnrolled', ['deviceId' => $device->id])
        ->assertSee('LATE-ENROLLER');
});

it('refreshes the device list when a device changes', function (): void {
    $device = Device::factory()->active()->create(['last_seen' => now()->subHour()]);

    $component = Livewire::actingAs($this->user)->test(DevicesIndex::class)
        ->assertSee('Offline');

    $device->update(['last_seen' => now()]);

    $component->dispatch('echo-private:devices,DeviceUpdated', ['deviceId' => $device->id, 'status' => 'active'])
        ->assertSee('Online')
        ->assertDontSee('Offline');
});

it('updates the pending list as devices enrol and get approved elsewhere', function (): void {
    $component = Livewire::actingAs($this->user)->test(Pending::class)
        ->assertSee('No pending devices');

    $device = Device::factory()->create(['hostname' => 'NEW-ARRIVAL']);

    $component->dispatch('echo-private:devices,DeviceEnrolled', ['deviceId' => $device->id])
        ->assertSee('NEW-ARRIVAL');

    $device->issueApiKey();

    $component->dispatch('echo-private:devices,DeviceUpdated', ['deviceId' => $device->id, 'status' => 'active'])
        ->assertDontSee('NEW-ARRIVAL');
});

it('refreshes the device page from its own channel', function (): void {
    $device = Device::factory()->active()->create(['last_seen' => now()->subHour()]);

    $component = Livewire::actingAs($this->user)->test(Show::class, ['device' => $device])
        ->assertSee('Offline');

    $device->update(['last_seen' => now(), 'status' => DeviceStatus::Active]);

    $component->dispatch("echo-private:devices.{$device->id},DeviceUpdated", ['deviceId' => $device->id, 'status' => 'active'])
        ->assertSee('Online')
        ->assertDontSee('Offline');
});

it('shows new commands on the device page as they are queued elsewhere', function (): void {
    $device = Device::factory()->active()->create();

    $component = Livewire::actingAs($this->user)->test(Show::class, ['device' => $device])
        ->assertDontSee('Recent Commands');

    $command = DeviceCommand::factory()->pending()->create(['device_id' => $device->id]);

    $component->dispatch("echo-private:devices.{$device->id},CommandUpdated", ['commandId' => $command->id, 'deviceId' => $device->id, 'status' => 'pending'])
        ->assertSee('Recent Commands')
        ->assertSeeHtml("commandId: {$command->id} ");
});

it('refreshes an open command when that command changes', function (): void {
    $command = DeviceCommand::factory()->create(['status' => CommandStatus::Running, 'output' => null]);

    $component = Livewire::actingAs($this->user)->test(Detail::class)
        ->dispatch('show-command', commandId: $command->id)
        ->assertSee('Running');

    $command->markAsCompleted('FRESH-OUTPUT', 0);

    $component->dispatch('echo-private:devices,CommandUpdated', ['commandId' => $command->id, 'deviceId' => $command->device_id, 'status' => 'completed'])
        ->assertSee('FRESH-OUTPUT')
        ->assertSee('Completed');
});

it('ignores updates for a command that is not open', function (): void {
    $shown = DeviceCommand::factory()->create(['status' => CommandStatus::Running]);
    $other = DeviceCommand::factory()->create();

    $component = Livewire::actingAs($this->user)->test(Detail::class)
        ->dispatch('show-command', commandId: $shown->id);

    $shown->markAsCompleted('SHOULD-NOT-APPEAR-YET', 0);

    $component->dispatch('echo-private:devices,CommandUpdated', ['commandId' => $other->id, 'deviceId' => $other->device_id, 'status' => 'completed'])
        ->assertDontSee('SHOULD-NOT-APPEAR-YET');
});

it('refreshes script executions when a command changes', function (): void {
    $script = Script::factory()->create();
    $command = DeviceCommand::factory()->create(['script_id' => $script->id, 'status' => CommandStatus::Running]);

    $component = Livewire::actingAs($this->user)->test(ScriptsShow::class, ['script' => $script])
        ->assertSee('Running');

    $command->markAsCompleted('done', 0);

    $component->dispatch('echo-private:devices,CommandUpdated', ['commandId' => $command->id, 'deviceId' => $command->device_id, 'status' => 'completed'])
        ->assertSee('Completed')
        ->assertDontSee('Running');
});

it('refreshes the alert list when an alert changes', function (): void {
    $component = Livewire::actingAs($this->user)->test(AlertsIndex::class)
        ->assertSee('No alerts');

    $alert = Alert::factory()->triggered()->create();

    $component->dispatch('echo-private:devices,AlertChanged', ['alertId' => $alert->id, 'deviceId' => $alert->device_id, 'status' => 'triggered'])
        ->assertSee($alert->device->hostname);
});

it('updates the alert bell from the user notification channel without polling', function (): void {
    $component = Livewire::withoutLazyLoading()->actingAs($this->user)->test(AlertBell::class)
        ->assertDontSeeHtml('wire:poll')
        ->assertViewHas('unreadCount', 0);

    $alert = Alert::factory()->triggered()->create();

    $component->dispatch("echo-private:App.Models.User.{$this->user->id},.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated", ['id' => $this->user->notifications()->sole()->id, 'alertId' => $alert->id])
        ->assertViewHas('unreadCount', 1);
});

it('has no polling anywhere in the views', function (): void {
    $polling = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), 'wire:poll'));

    expect($polling)->toBeEmpty();
});
