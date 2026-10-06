<?php

declare(strict_types=1);

use App\Actions\Device\BulkExecuteScript;
use App\Actions\Schedule\RunScheduledTask;
use App\Actions\Script\ExecuteScriptOnDevice;
use App\Livewire\Devices\Header as DeviceHeader;
use App\Livewire\Devices\Index as DevicesIndex;
use App\Livewire\ScheduledTasks\Index as ScheduledTasksIndex;
use App\Livewire\Scripts\Show as ScriptShow;
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
    $this->script = Script::factory()->create([
        'parameters' => [
            ['name' => 'PackageId', 'label' => 'Package ID', 'type' => 'text', 'required' => true],
            ['name' => 'Force', 'label' => 'Force', 'type' => 'boolean', 'required' => false, 'default' => 'true'],
            ['name' => 'Scope', 'label' => 'Scope', 'type' => 'choice', 'required' => false, 'options' => ['machine', 'user']],
        ],
    ]);
});

function deviceOnAgent(?string $version): Device
{
    return Device::factory()->active()->create(['agent_version' => $version]);
}

describe('version gate', function (): void {
    it('queues a parameterised script on an agent that supports parameters', function (string $version): void {
        $command = app(ExecuteScriptOnDevice::class)($this->script, deviceOnAgent($version), $this->user, parameters: ['PackageId' => 'Git.Git']);

        expect($command->parameters)->toBe(['PackageId' => 'Git.Git']);
    })->with(['0.6.2', '0.7.0', '1.0.0']);

    it('refuses a parameterised script on an old or unknown agent with a clear message', function (?string $version, string $message): void {
        $device = deviceOnAgent($version);

        expect(fn () => app(ExecuteScriptOnDevice::class)($this->script, $device, $this->user, parameters: ['PackageId' => 'Git.Git']))
            ->toThrow(ValidationException::class, "{$device->hostname} runs {$message}. Scripts with parameters need agent 0.6.2 or newer, so update the agent first.");

        expect(DeviceCommand::count())->toBe(0);
    })->with([
        'old agent' => ['0.6.1', 'agent 0.6.1'],
        'unknown agent' => [null, 'an unknown agent version'],
    ]);

    it('still queues scripts without parameters on old and unknown agents', function (?string $version): void {
        $command = app(ExecuteScriptOnDevice::class)(Script::factory()->create(), deviceOnAgent($version), $this->user);

        expect($command->exists)->toBeTrue();
        expect($command->getRawOriginal('parameters'))->toBeNull();
    })->with(['0.5.0', null]);

    it('reads the minimum version from config', function (): void {
        config(['agent.parameters_min_version' => '0.9.0']);

        expect(fn () => app(ExecuteScriptOnDevice::class)($this->script, deviceOnAgent('0.6.2'), $this->user, parameters: ['PackageId' => 'Git.Git']))
            ->toThrow(ValidationException::class);
    });
});

describe('pending payload', function (): void {
    it('sends parameters to the agent', function (): void {
        $device = Device::factory()->active()->withApiKey('VALID-KEY-123')->create(['agent_version' => '0.6.2']);
        app(ExecuteScriptOnDevice::class)($this->script, $device, $this->user, parameters: ['PackageId' => 'Mozilla.Firefox', 'Force' => 'true']);

        $this->withHeaders(['X-Agent-Key' => 'VALID-KEY-123'])
            ->getJson('/api/commands/pending')
            ->assertSuccessful()
            ->assertJsonPath('command.parameters', ['PackageId' => 'Mozilla.Firefox', 'Force' => 'true']);
    });

    it('sends an empty object when a command has no parameters', function (): void {
        $device = Device::factory()->active()->withApiKey('VALID-KEY-123')->create();
        app(ExecuteScriptOnDevice::class)(Script::factory()->create(), $device, $this->user);

        $response = $this->withHeaders(['X-Agent-Key' => 'VALID-KEY-123'])->getJson('/api/commands/pending');

        $response->assertSuccessful();
        expect($response->getContent())->toContain('"parameters":{}');
    });
});

describe('device page', function (): void {
    it('renders inputs for the selected script and queues with normalised values', function (): void {
        $device = deviceOnAgent('0.6.2');

        Livewire::actingAs($this->user)
            ->test(DeviceHeader::class, ['device' => $device])
            ->set('selectedScriptId', $this->script->id)
            ->assertSet('parameterValues', ['PackageId' => '', 'Force' => true, 'Scope' => ''])
            ->assertSeeHtml('wire:model="parameterValues.PackageId"')
            ->assertSeeHtml('wire:model="parameterValues.Force"')
            ->assertSeeHtml('wire:model="parameterValues.Scope"')
            ->set('parameterValues.PackageId', 'Mozilla.Firefox')
            ->set('parameterValues.Scope', 'machine')
            ->call('runScript')
            ->assertHasNoErrors()
            ->assertDispatched('command-queued')
            ->assertSet('parameterValues', []);

        expect(DeviceCommand::sole()->parameters)->toBe(['PackageId' => 'Mozilla.Firefox', 'Force' => 'true', 'Scope' => 'machine']);
    });

    it('shows value errors instead of queuing', function (): void {
        Livewire::actingAs($this->user)
            ->test(DeviceHeader::class, ['device' => deviceOnAgent('0.6.2')])
            ->set('selectedScriptId', $this->script->id)
            ->set('parameterValues.Scope', 'everyone')
            ->call('runScript')
            ->assertHasErrors(['parameterValues.PackageId' => 'Package ID is required.', 'parameterValues.Scope']);

        expect(DeviceCommand::count())->toBe(0);
    });

    it('shows the outdated agent message instead of crashing', function (): void {
        Livewire::actingAs($this->user)
            ->test(DeviceHeader::class, ['device' => deviceOnAgent('0.6.1')])
            ->set('selectedScriptId', $this->script->id)
            ->set('parameterValues.PackageId', 'Git.Git')
            ->call('runScript')
            ->assertHasErrors(['script'])
            ->assertSee('Scripts with parameters need agent 0.6.2 or newer');

        expect(DeviceCommand::count())->toBe(0);
    });

    it('renders no parameter inputs for a script without parameters', function (): void {
        Livewire::actingAs($this->user)
            ->test(DeviceHeader::class, ['device' => deviceOnAgent(null)])
            ->set('selectedScriptId', Script::factory()->create()->id)
            ->assertSet('parameterValues', [])
            ->assertDontSeeHtml('wire:model="parameterValues.')
            ->call('runScript')
            ->assertHasNoErrors();

        expect(DeviceCommand::count())->toBe(1);
    });
});

it('queues with values from the script page', function (): void {
    $device = deviceOnAgent('0.6.2');

    Livewire::actingAs($this->user)
        ->test(ScriptShow::class, ['script' => $this->script])
        ->assertSet('parameterValues.Force', true)
        ->set('selectedDeviceIds', [$device->id])
        ->set('parameterValues.PackageId', 'Git.Git')
        ->set('parameterValues.Force', false)
        ->call('executeOnDevices')
        ->assertHasNoErrors();

    expect(DeviceCommand::sole()->parameters)->toBe(['PackageId' => 'Git.Git', 'Force' => 'false']);
});

describe('bulk run', function (): void {
    it('skips devices on old agents, logs them and queues the rest', function (): void {
        Log::spy();
        Log::shouldReceive('channel')->andReturnSelf();
        $current = deviceOnAgent('0.6.2');
        $old = deviceOnAgent('0.6.1');
        $unknown = deviceOnAgent(null);

        $queued = app(BulkExecuteScript::class)($this->script, collect([$current, $old, $unknown]), $this->user, ['PackageId' => 'Git.Git']);

        expect($queued)->toBe(1);
        expect(DeviceCommand::sole()->device_id)->toBe($current->id);
        expect(DeviceCommand::sole()->parameters)->toBe(['PackageId' => 'Git.Git']);
        Log::shouldHaveReceived('warning')->with('script.skipped_outdated_agent', Mockery::on(fn (array $context): bool => $context['device_id'] === $old->id))->once();
        Log::shouldHaveReceived('warning')->with('script.skipped_outdated_agent', Mockery::on(fn (array $context): bool => $context['device_id'] === $unknown->id))->once();
    });

    it('does not toast when every selected device was queued', function (): void {
        Livewire::actingAs($this->user)
            ->test(DevicesIndex::class)
            ->set('selectedDevices', [(string) deviceOnAgent('0.6.2')->id])
            ->set('bulkScriptId', $this->script->id)
            ->set('parameterValues.PackageId', 'Git.Git')
            ->call('bulkRunScript')
            ->assertNotDispatched('toast-show');

        expect(DeviceCommand::count())->toBe(1);
    });

    it('does not skip old agents for scripts without parameters', function (): void {
        $queued = app(BulkExecuteScript::class)(Script::factory()->create(), collect([deviceOnAgent('0.6.1'), deviceOnAgent(null)]), $this->user);

        expect($queued)->toBe(2);
    });

    it('validates values and reports skipped devices from the devices page', function (): void {
        $current = deviceOnAgent('0.6.2');
        $old = deviceOnAgent('0.6.1');

        $component = Livewire::actingAs($this->user)
            ->test(DevicesIndex::class)
            ->set('selectedDevices', [(string) $current->id, (string) $old->id])
            ->set('bulkScriptId', $this->script->id)
            ->assertSeeHtml('wire:model="parameterValues.PackageId"')
            ->call('bulkRunScript')
            ->assertHasErrors(['parameterValues.PackageId']);

        expect(DeviceCommand::count())->toBe(0);

        $component->set('parameterValues.PackageId', 'Git.Git')
            ->call('bulkRunScript')
            ->assertHasNoErrors()
            ->assertDispatched('command-queued')
            ->assertDispatched('toast-show');

        expect(DeviceCommand::sole()->device_id)->toBe($current->id);
        expect(DeviceCommand::sole()->parameters)->toBe(['PackageId' => 'Git.Git', 'Force' => 'true']);
    });
});

describe('scheduled tasks', function (): void {
    it('stores normalised values with the schedule', function (): void {
        Livewire::actingAs($this->user)
            ->test(ScheduledTasksIndex::class)
            ->set('name', 'Install Git nightly')
            ->set('script_id', $this->script->id)
            ->assertSet('parameterValues', ['PackageId' => '', 'Force' => true, 'Scope' => ''])
            ->assertSeeHtml('wire:model="parameterValues.PackageId"')
            ->set('parameterValues.PackageId', 'Git.Git')
            ->set('parameterValues.Force', false)
            ->call('create')
            ->assertHasNoErrors();

        expect(ScheduledTask::sole()->parameters)->toBe(['PackageId' => 'Git.Git', 'Force' => 'false']);
    });

    it('validates values before saving the schedule', function (): void {
        Livewire::actingAs($this->user)
            ->test(ScheduledTasksIndex::class)
            ->set('name', 'Install nothing')
            ->set('script_id', $this->script->id)
            ->call('create')
            ->assertHasErrors(['parameterValues.PackageId' => 'Package ID is required.']);

        expect(ScheduledTask::count())->toBe(0);
    });

    it('loads stored values when editing and updates them', function (): void {
        $task = ScheduledTask::factory()->create([
            'script_id' => $this->script->id,
            'parameters' => ['PackageId' => 'Git.Git', 'Force' => 'false'],
        ]);

        Livewire::actingAs($this->user)
            ->test(ScheduledTasksIndex::class)
            ->call('edit', $task->id)
            ->assertSet('parameterValues', ['PackageId' => 'Git.Git', 'Force' => false, 'Scope' => ''])
            ->set('parameterValues.Scope', 'user')
            ->call('update')
            ->assertHasNoErrors();

        expect($task->fresh()->parameters)->toBe(['PackageId' => 'Git.Git', 'Force' => 'false', 'Scope' => 'user']);
    });

    it('stores no values for wake schedules or scripts without parameters', function (string $action, bool $withScript): void {
        Livewire::actingAs($this->user)
            ->test(ScheduledTasksIndex::class)
            ->set('name', 'Plain')
            ->set('action', $action)
            ->set('script_id', $withScript ? Script::factory()->create()->id : null)
            ->call('create')
            ->assertHasNoErrors();

        expect(ScheduledTask::sole()->parameters)->toBeNull();
    })->with([
        'wake' => ['wake', false],
        'plain script' => ['run_script', true],
    ]);

    it('passes stored values to each run and skips old agents', function (): void {
        $current = deviceOnAgent('0.6.2');
        deviceOnAgent('0.6.1');
        $task = ScheduledTask::factory()->create([
            'script_id' => $this->script->id,
            'parameters' => ['PackageId' => 'Git.Git'],
            'created_by' => $this->user->id,
        ]);

        $count = app(RunScheduledTask::class)($task);

        expect($count)->toBe(1);
        expect(DeviceCommand::sole()->device_id)->toBe($current->id);
        expect(DeviceCommand::sole()->parameters)->toBe(['PackageId' => 'Git.Git']);
        expect(DeviceCommand::sole()->scheduled_task_id)->toBe($task->id);
        expect($task->fresh()->last_run_at)->not->toBeNull();
    });
});
