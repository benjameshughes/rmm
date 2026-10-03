<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\CommandStatus;
use App\Enums\ScriptPlatform;
use App\Livewire\Commands\Detail;
use App\Livewire\Devices\Commands;
use App\Livewire\Devices\Header;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('queues a pending ad-hoc command with exactly what was typed', function (): void {
    $device = Device::factory()->active()->windows()->create();
    $commandText = "ipconfig /flushdns\nipconfig /registerdns";

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->set('commandText', $commandText)
        ->set('commandType', 'cmd')
        ->set('commandTimeoutSeconds', 120)
        ->call('runAdHocCommand')
        ->assertHasNoErrors();

    $command = DeviceCommand::query()->sole();

    expect($command->device_id)->toBe($device->id)
        ->and($command->script_id)->toBeNull()
        ->and($command->isAdHoc())->toBeTrue()
        ->and($command->script_content)->toBe($commandText)
        ->and($command->script_type)->toBe('cmd')
        ->and($command->timeout_seconds)->toBe(120)
        ->and($command->status)->toBe(CommandStatus::Pending)
        ->and($command->queued_by)->toBe($this->user->id)
        ->and($command->queued_at)->not->toBeNull()
        ->and($command->parameters)->toBeNull();
});

it('closes the modal, resets the form, refreshes the list and toasts', function (): void {
    $device = Device::factory()->active()->windows()->create();

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->set('showCommandModal', true)
        ->set('commandText', 'Get-Process')
        ->set('commandType', 'cmd')
        ->set('commandTimeoutSeconds', 60)
        ->call('runAdHocCommand')
        ->assertDispatched('command-queued')
        ->assertDispatched('toast-show')
        ->assertSet('showCommandModal', false)
        ->assertSet('commandText', '')
        ->assertSet('commandType', 'powershell')
        ->assertSet('commandTimeoutSeconds', config('commands.ad_hoc.timeout_seconds.default'));
});

it('offers only the shells the device platform can run, defaulting to the first', function (string $factoryState, array $types): void {
    $device = Device::factory()->active()->{$factoryState}()->create();

    $component = Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->assertSet('commandType', $types[0])
        ->assertSet('commandTimeoutSeconds', config('commands.ad_hoc.timeout_seconds.default'));

    expect(collect($component->instance()->commandTypes)->map->value->all())->toBe($types);
})->with([
    'Windows' => ['windows', ['powershell', 'cmd']],
    'Linux' => ['linux', ['bash']],
]);

it('works out the platform from whatever OS fields the agent reported', function (array $attributes, ScriptPlatform $platform): void {
    $device = Device::factory()->make(['os' => null, 'os_name' => null, 'kernel_name' => null, ...$attributes]);

    expect($device->platform())->toBe($platform);
})->with([
    'enrolment os' => [['os' => 'Windows 11 Pro'], ScriptPlatform::Windows],
    'reported os name' => [['os_name' => 'Microsoft Windows Server 2022'], ScriptPlatform::Windows],
    'netdata kernel name' => [['kernel_name' => 'windows'], ScriptPlatform::Windows],
    'linux' => [['os' => 'Ubuntu 24.04 LTS', 'os_name' => 'Ubuntu', 'kernel_name' => 'Linux'], ScriptPlatform::Linux],
    'nothing reported yet' => [[], ScriptPlatform::Linux],
]);

it('validates the command before queueing anything', function (string $factoryState, array $input, string $field, string $message): void {
    config()->set('commands.ad_hoc.max_length', 20);
    $device = Device::factory()->active()->{$factoryState}()->create();

    $component = Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->set('commandText', 'hostname');

    collect($input)->each(fn (mixed $value, string $property) => $component->set($property, $value));

    $component->call('runAdHocCommand')
        ->assertHasErrors([$field])
        ->assertSee($message)
        ->assertNotDispatched('command-queued');

    expect(DeviceCommand::query()->count())->toBe(0);
})->with([
    'empty command' => ['windows', ['commandText' => ''], 'commandText', 'Type the command to run.'],
    'whitespace only' => ['windows', ['commandText' => '   '], 'commandText', 'Type the command to run.'],
    'too long' => ['windows', ['commandText' => str_repeat('a', 21)], 'commandText', 'Commands may not be longer than 20 characters.'],
    'bash on Windows' => ['windows', ['commandType' => 'bash'], 'commandType', 'Choose a shell this device can run.'],
    'PowerShell on Linux' => ['linux', ['commandType' => 'powershell'], 'commandType', 'Choose a shell this device can run.'],
    'not a shell at all' => ['linux', ['commandType' => 'python'], 'commandType', 'Choose a shell this device can run.'],
    'timeout too short' => ['windows', ['commandTimeoutSeconds' => 9], 'commandTimeoutSeconds', 'The timeout must be at least 10 seconds.'],
    'timeout too long' => ['windows', ['commandTimeoutSeconds' => 7201], 'commandTimeoutSeconds', 'The timeout may not be more than 7200 seconds.'],
]);

it('accepts the timeout bounds themselves', function (int $timeoutSeconds): void {
    $device = Device::factory()->active()->linux()->create();

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->set('commandText', 'uptime')
        ->set('commandTimeoutSeconds', $timeoutSeconds)
        ->call('runAdHocCommand')
        ->assertHasNoErrors();

    expect(DeviceCommand::query()->sole())
        ->timeout_seconds->toBe($timeoutSeconds)
        ->script_type->toBe('bash');
})->with([10, 7200]);

it('checks the runAdHocCommand ability before queueing anything', function (): void {
    $device = Device::factory()->active()->windows()->create();
    $component = Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->set('commandText', 'Stop-Computer -Force');
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'runAdHocCommand' ? false : null);

    $component->call('runAdHocCommand')->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('hands the command to the agent like any other, with empty parameters', function (): void {
    $device = Device::factory()->active()->linux()->withApiKey('ADHOC-KEY')->create();

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->set('commandText', 'df -h')
        ->set('commandTimeoutSeconds', 45)
        ->call('runAdHocCommand');

    $command = DeviceCommand::query()->sole();

    $response = $this->withHeaders(['X-Agent-Key' => 'ADHOC-KEY'])
        ->getJson('/api/commands/pending')
        ->assertSuccessful()
        ->assertExactJson([
            'command' => [
                'id' => $command->id,
                'script_content' => 'df -h',
                'script_type' => 'bash',
                'timeout_seconds' => 45,
                'parameters' => [],
            ],
        ]);

    expect($response->getContent())->toContain('"parameters":{}')
        ->and($command->fresh()->status)->toBe(CommandStatus::Sent);
});

it('audits the full command text and who ran it', function (): void {
    $device = Device::factory()->active()->windows()->create(['hostname' => 'TILL-01']);
    $commandText = "Get-Process\n| Where-Object CPU -gt 50\n| Stop-Process -Force";

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->set('commandText', $commandText)
        ->call('runAdHocCommand');

    $audit = AuditLog::query()->where('action', AuditAction::CommandQueued)->sole();

    expect($audit->user_id)->toBe($this->user->id)
        ->and($audit->subject_id)->toBe(DeviceCommand::query()->sole()->id)
        ->and($audit->properties['command'])->toBe($commandText)
        ->and($audit->properties['label'])->toBe('Ad-hoc command: Get-Process on TILL-01')
        ->and($audit->properties)->not->toHaveKey('script_name');
});

it('names an ad-hoc command after the first line of what was typed', function (string $commandText, string $expected): void {
    config()->set('commands.ad_hoc.label_max_length', 20);

    $command = DeviceCommand::factory()->create(['script_id' => null, 'script_content' => $commandText]);

    expect($command->displayName())->toBe($expected);
})->with([
    'one line' => ['hostname', 'Ad-hoc command: hostname'],
    'skips blank lines' => ["\n\n  whoami  \nhostname", 'Ad-hoc command: whoami'],
    'truncated' => ['Get-ChildItem -Recurse C:\\Users', 'Ad-hoc command: Get-ChildItem -Recur...'],
    'nothing printable' => ["  \n ", 'Ad-hoc command'],
]);

it('lists an ad-hoc command on the commands tab and opens its detail', function (): void {
    $device = Device::factory()->active()->windows()->create();
    $command = DeviceCommand::factory()->completed()->create([
        'device_id' => $device->id,
        'script_id' => null,
        'script_content' => "Get-Service Spooler\nRestart-Service Spooler",
        'script_type' => 'powershell',
        'output' => 'Spooler restarted',
    ]);

    Livewire::actingAs($this->user)->test(Commands::class, ['device' => $device])
        ->assertSee('Ad-hoc command: Get-Service Spooler');

    Livewire::actingAs($this->user)->test(Detail::class)
        ->dispatch('show-command', commandId: $command->id)
        ->assertSee('Ad-hoc command: Get-Service Spooler')
        ->assertSee('Spooler restarted')
        ->assertSee('Restart-Service Spooler');
});
