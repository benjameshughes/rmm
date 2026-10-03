<?php

declare(strict_types=1);

use App\Actions\Device\BulkExecuteScript;
use App\Actions\Device\RunAdHocCommand;
use App\Actions\Device\WakeDevice;
use App\Actions\Schedule\RunScheduledTask;
use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Enums\DeviceStatus;
use App\Enums\ScheduleTargetType;
use App\Enums\ScriptType;
use App\Livewire\Devices\Commands;
use App\Livewire\Devices\Header;
use App\Livewire\Devices\Index;
use App\Livewire\Devices\Pending;
use App\Livewire\Scripts\Show as ScriptsShow;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

function postLinuxMetrics(array $overrides = [], string $apiKey = 'LINUX-KEY'): Illuminate\Testing\TestResponse
{
    return test()->withHeaders(['X-Device-Key' => $apiKey])->postJson('/api/metrics', [
        'cpu' => ['usage_percent' => 12.5],
        'memory' => ['usage_percent' => 40.0],
        'agent_version' => '0.7.0',
        'system_info' => ['os_name' => 'Debian GNU/Linux', 'kernel_name' => 'Linux'],
        ...$overrides,
    ]);
}

describe('the monitor-only latch', function (): void {
    it('marks a device monitor only when its agent reports it', function (): void {
        $device = Device::factory()->withApiKey('LINUX-KEY')->create();

        postLinuxMetrics(['monitor_only' => true])->assertSuccessful();

        expect($device->fresh()->isMonitorOnly)->toBeTrue();
    });

    it('never clears the flag when a later report says false or nothing', function (array $laterReport): void {
        $device = Device::factory()->withApiKey('LINUX-KEY')->create();

        postLinuxMetrics(['monitor_only' => true])->assertSuccessful();
        postLinuxMetrics($laterReport)->assertSuccessful();

        expect($device->fresh()->isMonitorOnly)->toBeTrue();
    })->with([
        'false' => [['monitor_only' => false]],
        'null' => [['monitor_only' => null]],
        'absent' => [[]],
        'zero' => [['monitor_only' => 0]],
    ]);

    it('keeps the flag through a raw Netdata report too', function (): void {
        $device = Device::factory()->monitorOnly()->withApiKey('LINUX-KEY')->create();

        test()->withHeaders(['X-Device-Key' => 'LINUX-KEY'])->postJson('/api/metrics', ['netdata_cpu' => []])->assertSuccessful();

        expect($device->fresh()->isMonitorOnly)->toBeTrue();
    });

    it('leaves a full agent with commands when it does not report the flag', function (): void {
        $device = Device::factory()->windows()->withApiKey('LINUX-KEY')->create();

        postLinuxMetrics(['monitor_only' => false])->assertSuccessful();

        expect($device->fresh()->isMonitorOnly)->toBeFalse();
    });

    it('rejects a flag that is not a boolean', function (): void {
        Device::factory()->withApiKey('LINUX-KEY')->create();

        postLinuxMetrics(['monitor_only' => 'please'])->assertUnprocessable()->assertJsonValidationErrors('monitor_only');
    });

    it('treats a device that has not reported yet as a full agent', function (): void {
        $device = Device::factory()->active()->create(['is_monitor_only' => null]);

        expect($device->isMonitorOnly)->toBeFalse()
            ->and(Device::query()->acceptsCommands()->whereKey($device->id)->exists())->toBeTrue();
    });
});

describe('the device policy', function (): void {
    it('refuses every command and power ability on a monitor-only device', function (string $ability): void {
        expect($this->user->can($ability, Device::factory()->monitorOnly()->active()->create()))->toBeFalse()
            ->and($this->user->can($ability, Device::factory()->active()->create()))->toBeTrue();
    })->with(['runCommands', 'runAdHocCommand', 'wake']);

    it('still allows looking after a monitor-only device', function (string $ability): void {
        expect($this->user->can($ability, Device::factory()->monitorOnly()->active()->create()))->toBeTrue();
    })->with(['view', 'approve', 'reject', 'resetEnrolment', 'manageGroupsAndTags', 'delete']);

    it('refuses to cancel a command left pending on a monitor-only device', function (): void {
        $command = DeviceCommand::factory()->pending()->create([
            'device_id' => Device::factory()->monitorOnly()->active()->create()->id,
            'queued_by' => $this->user->id,
        ]);

        expect($this->user->can('cancel', $command))->toBeFalse();
    });
});

describe('the agent API', function (): void {
    it('never hands a monitor-only device a command, even one already queued', function (): void {
        $device = Device::factory()->monitorOnly()->withApiKey('LINUX-KEY')->create();
        $command = DeviceCommand::factory()->pending()->create(['device_id' => $device->id]);

        test()->withHeaders(['X-Device-Key' => 'LINUX-KEY'])->getJson('/api/commands/pending')
            ->assertSuccessful()
            ->assertExactJson(['command' => null]);

        expect($command->fresh()->status)->toBe(CommandStatus::Pending);
    });
});

describe('the actions', function (): void {
    it('refuses to queue a script on a monitor-only device', function (): void {
        $device = Device::factory()->monitorOnly()->active()->create();

        expect(fn () => app(ExecuteScriptOnDevice::class)(Script::factory()->create(), $device, $this->user))
            ->toThrow(ValidationException::class, 'is monitor only');

        expect(DeviceCommand::query()->count())->toBe(0);
    });

    it('refuses to queue an ad-hoc command on a monitor-only device', function (): void {
        $device = Device::factory()->monitorOnly()->active()->create();

        expect(fn () => app(RunAdHocCommand::class)($device, $this->user, 'rm -rf /', ScriptType::Bash, 60))
            ->toThrow(ValidationException::class, 'is monitor only');

        expect(DeviceCommand::query()->count())->toBe(0);
    });

    it('refuses to wake a monitor-only device', function (): void {
        $listener = listenForWakePackets();
        $device = Device::factory()->monitorOnly()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

        expect(fn () => app(WakeDevice::class)($device))->toThrow(RuntimeException::class, 'is monitor only');

        expect(receivedWakePackets($listener))->toBe([]);
    });

    it('skips monitor-only devices in a bulk run and logs it', function (): void {
        Log::spy();
        Log::shouldReceive('channel')->andReturnSelf();
        $script = Script::factory()->create();
        $monitorOnly = Device::factory()->monitorOnly()->active()->create();
        $full = Device::factory()->active()->create();

        $queued = app(BulkExecuteScript::class)($script, collect([$monitorOnly, $full]), $this->user);

        expect($queued)->toBe(1)
            ->and(DeviceCommand::query()->sole()->device_id)->toBe($full->id);
        Log::shouldHaveReceived('info')->with('script.skipped_monitor_only', ['script_id' => $script->id, 'device_id' => $monitorOnly->id])->once();
    });

    it('skips monitor-only devices when a schedule runs a script', function (): void {
        Device::factory()->monitorOnly()->active()->create();
        $full = Device::factory()->active()->create();
        $task = ScheduledTask::factory()->create([
            'script_id' => Script::factory()->create()->id,
            'target_type' => ScheduleTargetType::All,
            'created_by' => $this->user->id,
        ]);

        expect(app(RunScheduledTask::class)($task))->toBe(1)
            ->and(DeviceCommand::query()->sole()->device_id)->toBe($full->id);
    });

    it('skips monitor-only devices when a schedule wakes devices and logs it', function (): void {
        Log::spy();
        Log::shouldReceive('channel')->andReturnSelf();
        $listener = listenForWakePackets();
        $monitorOnly = Device::factory()->monitorOnly()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:AA:AA:AA:AA:01']]);
        Device::factory()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:AA:AA:AA:AA:02']]);
        $task = ScheduledTask::factory()->wake()->create(['target_type' => ScheduleTargetType::All]);

        expect(app(RunScheduledTask::class)($task))->toBe(1)
            ->and(receivedWakePackets($listener))->toBe(array_fill(0, 3, magicPacketFor('AA:AA:AA:AA:AA:02')));
        Log::shouldHaveReceived('info')->with('wake.skipped_monitor_only', ['scheduled_task_id' => $task->id, 'device_id' => $monitorOnly->id])->once();
    });

    it('does not prepare Wake-on-LAN when a monitor-only device is approved', function (): void {
        Script::factory()->system()->create(['slug' => 'prepare-wake-on-lan']);
        $device = Device::factory()->windows()->create(['status' => DeviceStatus::Pending, 'is_monitor_only' => true]);

        Livewire::actingAs($this->user)->test(Pending::class)->call('approve', $device->id);

        expect($device->fresh()->status)->toBe(DeviceStatus::Active)
            ->and(DeviceCommand::query()->exists())->toBeFalse();
    });
});

describe('the device pages', function (): void {
    beforeEach(function (): void {
        app(SyncSystemScripts::class)();
    });

    it('hides every command and power control in the header and shows the badge', function (): void {
        $device = Device::factory()->monitorOnly()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

        Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
            ->assertSee('Monitor only')
            ->assertSeeHtml('data-monitor-only')
            ->assertDontSeeHtml('wire:click="wake"')
            ->assertDontSeeHtml('showCommandModal\', true')
            ->assertDontSeeHtml('showScriptModal\', true')
            ->assertDontSeeHtml('wire:click="restart"')
            ->assertDontSeeHtml('wire:click="powerOff"')
            ->assertDontSeeHtml('wire:click="logOff"');
    });

    it('keeps the controls and drops the badge for a full agent', function (): void {
        $device = Device::factory()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

        Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
            ->assertDontSeeHtml('data-monitor-only')
            ->assertSeeHtml('wire:click="wake"')
            ->assertSeeHtml('wire:click="restart"')
            ->assertSeeHtml('showCommandModal\', true')
            ->assertSeeHtml('showScriptModal\', true');
    });

    it('refuses header actions called directly on a monitor-only device', function (string $method): void {
        $device = Device::factory()->monitorOnly()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

        Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
            ->set('commandText', 'id')
            ->set('commandType', 'bash')
            ->set('selectedScriptId', Script::query()->value('id'))
            ->call($method)
            ->assertForbidden();

        expect(DeviceCommand::query()->count())->toBe(0);
    })->with(['wake', 'restart', 'powerOff', 'logOff', 'checkForUpdates', 'updateAgent', 'runScript', 'runAdHocCommand']);

    it('shows the badge in the device list and hides the row wake and power actions', function (): void {
        $device = Device::factory()->monitorOnly()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

        Livewire::actingAs($this->user)->test(Index::class)
            ->assertSeeHtml('data-monitor-only')
            ->assertDontSeeHtml("wake({$device->id})")
            ->assertDontSeeHtml("restart({$device->id})")
            ->assertDontSeeHtml("powerOff({$device->id})");
    });

    it('skips monitor-only devices in a bulk selection rather than refusing the batch', function (): void {
        $monitorOnly = Device::factory()->monitorOnly()->active()->create();
        $full = Device::factory()->active()->create();

        Livewire::actingAs($this->user)->test(Index::class)
            ->set('selectedDevices', [(string) $monitorOnly->id, (string) $full->id])
            ->call('bulkRestart')
            ->assertSuccessful();

        expect(DeviceCommand::query()->sole()->device_id)->toBe($full->id);
    });

    it('refuses a single row action called directly on a monitor-only device', function (): void {
        $device = Device::factory()->monitorOnly()->active()->create();

        Livewire::actingAs($this->user)->test(Index::class)->call('restart', $device->id)->assertForbidden();

        expect(DeviceCommand::query()->count())->toBe(0);
    });

    it('leaves monitor-only devices out of the script page device picker', function (): void {
        Device::factory()->monitorOnly()->active()->create(['hostname' => 'linux-watch-only']);
        Device::factory()->windows()->active()->create(['hostname' => 'WINDOWS-BOX']);

        Livewire::actingAs($this->user)->test(ScriptsShow::class, ['script' => Script::factory()->create()])
            ->set('showExecuteModal', true)
            ->assertViewHas('availableDevices', fn ($devices): bool => $devices->pluck('hostname')->all() === ['WINDOWS-BOX']);
    });

    it('hides the cancel button for a command stuck pending on a monitor-only device', function (): void {
        $device = Device::factory()->monitorOnly()->active()->create();
        DeviceCommand::factory()->pending()->create(['device_id' => $device->id, 'queued_by' => $this->user->id]);

        Livewire::actingAs($this->user)->test(Commands::class, ['device' => $device])
            ->assertDontSeeHtml('cancelCommand(');
    });
});
