<?php

declare(strict_types=1);

use App\Actions\Script\ResolveCommandSecrets;
use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Enums\MigrationMode;
use App\Enums\UserStateScript;
use App\Livewire\Devices\MigrateUser;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\StaffAccount;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->oldPc = Device::factory()->active()->withBackupCredentials()->create(['hostname' => 'OLD-PC', 'agent_version' => '0.7.1', 'last_seen' => now()]);
    $this->newPc = Device::factory()->active()->windows()->withApiKey('NEW-KEY')->create(['hostname' => 'NEW-PC', 'agent_version' => '0.7.1']);
    $this->account = StaffAccount::factory()->create(['username' => 'michelle', 'full_name' => 'Michelle Jones']);
    $this->account->devices()->attach($this->newPc);
});

function usmtCommand(Device $device, UserStateScript $script): DeviceCommand
{
    return DeviceCommand::query()->where('device_id', $device->id)->whereRelation('script', 'slug', $script->value)->sole();
}

/**
 * @param  array<string, mixed>  $result
 */
function usmtCaptureOutput(array $result): string
{
    return "OK: captured OLDDOMAIN\\michelle\r\nLog: C:\\ProgramData\\RMM\\usmt-logs\\scan.log\r\n".json_encode($result)."\r\n";
}

it('queues a capture on the old PC that will load into the staff account here', function (): void {
    Livewire::actingAs($this->user)->test(MigrateUser::class, ['device' => $this->newPc])
        ->call('open')
        ->assertSet('mode', MigrationMode::FromAnotherPc->value)
        ->assertSee('michelle')
        ->set('sourceDeviceId', $this->oldPc->id)
        ->set('fromUser', 'OLDDOMAIN\\michelle')
        ->set('staffAccountId', $this->account->id)
        ->call('migrate')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(usmtCommand($this->oldPc, UserStateScript::Capture)->parameters)->toBe([
        'FromUser' => 'OLDDOMAIN\\michelle',
        'ApplyDevice' => (string) $this->newPc->id,
        'ApplyUsername' => 'michelle',
    ])->and(DeviceCommand::query()->where('device_id', $this->newPc->id)->exists())->toBeFalse();
});

it('migrates in place on this PC', function (): void {
    Livewire::actingAs($this->user)->test(MigrateUser::class, ['device' => $this->newPc])
        ->call('open')
        ->set('mode', MigrationMode::OnThisPc->value)
        ->set('fromUser', 'OLDDOMAIN\\michelle')
        ->set('staffAccountId', $this->account->id)
        ->call('migrate')
        ->assertHasNoErrors();

    expect(usmtCommand($this->newPc, UserStateScript::MigrateInPlace)->parameters)->toBe([
        'FromUser' => 'OLDDOMAIN\\michelle',
        'Username' => 'michelle',
    ]);
});

it('refuses a migration that is not safe to queue', function (string $field, mixed $value, string $rule): void {
    $value = $value instanceof Closure ? $value() : $value;

    Livewire::actingAs($this->user)->test(MigrateUser::class, ['device' => $this->newPc])
        ->call('open')
        ->set('sourceDeviceId', $this->oldPc->id)
        ->set('fromUser', 'OLDDOMAIN\\michelle')
        ->set('staffAccountId', $this->account->id)
        ->set($field, $value)
        ->call('migrate')
        ->assertHasErrors([$field => $rule]);

    expect(DeviceCommand::query()->count())->toBe(0);
})->with([
    'no old PC' => ['sourceDeviceId', null, 'required'],
    'the same PC as the old PC' => ['sourceDeviceId', fn (): int => test()->newPc->id, 'in'],
    'a user with a quote' => ['fromUser', 'OLD"DOMAIN\\x', 'regex'],
    'a user with two backslashes' => ['fromUser', 'A\\B\\c', 'regex'],
    'no staff account' => ['staffAccountId', null, 'required'],
    'a staff account for another PC' => ['staffAccountId', fn (): int => StaffAccount::factory()->create()->id, 'in'],
    'an unknown mode' => ['mode', 'elsewhere', 'Illuminate\\Validation\\Rules\\Enum'],
]);

it('refuses to migrate onto a monitor-only PC', function (): void {
    $this->newPc->forceFill(['is_monitor_only' => true])->save();

    Livewire::actingAs($this->user)->test(MigrateUser::class, ['device' => $this->newPc])
        ->call('open')
        ->set('mode', MigrationMode::OnThisPc->value)
        ->set('fromUser', 'michelle')
        ->set('staffAccountId', $this->account->id)
        ->call('migrate')
        ->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('queues the apply on the new PC with the snapshot once the capture succeeds', function (): void {
    $capture = DeviceCommand::factory()->create([
        'device_id' => $this->oldPc->id,
        'script_id' => Script::findSystem(UserStateScript::Capture->value)->id,
        'parameters' => ['FromUser' => 'OLDDOMAIN\\michelle', 'ApplyDevice' => (string) $this->newPc->id, 'ApplyUsername' => 'michelle'],
        'status' => CommandStatus::Running,
        'queued_by' => $this->user->id,
    ]);
    $snapshotId = str_repeat('ab12', 16);

    $capture->markAsCompleted(usmtCaptureOutput(['status' => 'ok', 'exit_code' => 0, 'from_user' => 'OLDDOMAIN\\michelle', 'snapshot_id' => $snapshotId]), 0);
    $capture->markAsCompleted(usmtCaptureOutput(['status' => 'ok', 'exit_code' => 0, 'from_user' => 'OLDDOMAIN\\michelle', 'snapshot_id' => $snapshotId]), 0);

    $apply = usmtCommand($this->newPc, UserStateScript::Apply);

    expect($apply->parameters)->toBe([
        'SnapshotId' => $snapshotId,
        'SourceDevice' => (string) $this->oldPc->id,
        'FromUser' => 'OLDDOMAIN\\michelle',
        'Username' => 'michelle',
        'CaptureCommand' => (string) $capture->id,
    ])->and($apply->queued_by)->toBe($this->user->id)
        ->and(app(ResolveCommandSecrets::class)($apply)['RepositoryName'])->toBe($this->oldPc->backup_repository_name);
});

it('queues nothing when the capture fails', function (int $exitCode, array $result): void {
    $capture = DeviceCommand::factory()->create([
        'device_id' => $this->oldPc->id,
        'script_id' => Script::findSystem(UserStateScript::Capture->value)->id,
        'parameters' => ['FromUser' => 'michelle', 'ApplyDevice' => (string) $this->newPc->id, 'ApplyUsername' => 'michelle'],
        'status' => CommandStatus::Running,
        'queued_by' => $this->user->id,
    ]);

    $capture->markAsCompleted(usmtCaptureOutput($result), $exitCode);

    expect(DeviceCommand::query()->where('device_id', $this->newPc->id)->exists())->toBeFalse();
})->with([
    'scanstate failed' => [27, ['status' => 'failed', 'exit_code' => 27, 'from_user' => 'OLD-PC\\michelle', 'snapshot_id' => null]],
    'no snapshot reported' => [0, ['status' => 'ok', 'exit_code' => 0, 'from_user' => 'OLD-PC\\michelle']],
]);

it('queues nothing when the capture times out', function (): void {
    $capture = DeviceCommand::factory()->create([
        'device_id' => $this->oldPc->id,
        'script_id' => Script::findSystem(UserStateScript::Capture->value)->id,
        'parameters' => ['FromUser' => 'michelle', 'ApplyDevice' => (string) $this->newPc->id, 'ApplyUsername' => 'michelle'],
        'status' => CommandStatus::Running,
        'queued_by' => $this->user->id,
    ]);

    $capture->markAsTimedOut();

    expect(DeviceCommand::query()->where('device_id', $this->newPc->id)->exists())->toBeFalse();
});

it('syncs the USMT scripts with the USMT prelude and settings as secrets', function (): void {
    $slugs = collect(UserStateScript::cases())->map(fn (UserStateScript $script): string => $script->value);

    $slugs->each(function (string $slug): void {
        $script = Script::findSystem($slug);

        expect($script->script_content)->toContain('function Use-Usmt')->toContain('function Stop-Run')
            ->and($script->secretNames())->toContain('UsmtAdkUrl', 'UsmtXmlFiles');
    });

    expect(Script::findSystem(UserStateScript::Install->value)->is_internal)->toBeFalse()
        ->and(Script::findSystem(UserStateScript::Capture->value)->is_internal)->toBeTrue()
        ->and(Script::findSystem(UserStateScript::Apply->value)->parameters->pluck('name')->all())->toBe(['SnapshotId', 'SourceDevice', 'FromUser', 'Username', 'CaptureCommand']);

    $secrets = app(ResolveCommandSecrets::class)(DeviceCommand::factory()->create([
        'device_id' => $this->newPc->id,
        'script_id' => Script::findSystem(UserStateScript::Install->value)->id,
    ]));

    expect($secrets)->toBe([
        'UsmtAdkUrl' => config('usmt.adk_download_url'),
        'UsmtFolder' => 'usmt',
        'UsmtXmlFiles' => '["MigDocs.xml","MigApp.xml"]',
        'UsmtVerbosity' => '5',
    ]);
});

it('runs scanstate and loadstate with the agreed flags', function (): void {
    $capture = file_get_contents(resource_path('scripts/windows/capture-user-state.ps1'));
    $apply = file_get_contents(resource_path('scripts/windows/apply-user-state.ps1'));
    $inPlace = file_get_contents(resource_path('scripts/windows/migrate-user-in-place.ps1'));
    $prelude = file_get_contents(resource_path('scripts/windows/shared/usmt.ps1'));

    expect($capture)->toContain("'/ue:*\\*', \"/ui:\$qualifiedUser\"")->toContain("'/o', '/c', '/localonly'")->toContain("'--tag', 'usmt'")->toContain('Test-UserSignedIn')
        ->and($apply)->toContain('"/mu:${fromUser}:$targetUser"')->toContain('Get-LocalUser')->toContain('Test-UserSignedIn')
        ->and($inPlace)->toContain("'/hardlink', '/nocompress'")->toContain('cmd.exe /c rmdir /s /q')->not->toContain('& $usmtutils')
        ->and($prelude)->toContain('/quiet /norestart /ceip off /features OptionId.UserStateMigrationTool')
        ->toContain('$parsedUsmtXmlFiles = "$env:RMM_UsmtXmlFiles" | ConvertFrom-Json');
});

it('keeps Migrate User captures out of the file restores and the snapshot list', function (string $file): void {
    $script = file_get_contents(resource_path("scripts/windows/{$file}"));

    expect($script)->toMatch("/@\\('(restore|snapshots)', [^)]*'--tag', 'rmm'/");
})->with(['backup-restore.ps1', 'backup-restore-profile.ps1', 'backup-snapshots.ps1']);

it('tags captures usmt and never rmm, so the rmm filter leaves them out', function (): void {
    $capture = file_get_contents(resource_path('scripts/windows/capture-user-state.ps1'));

    expect($capture)->toContain("'--tag', 'usmt'")->not->toContain("'--tag', 'rmm'")
        ->and(file_get_contents(resource_path('scripts/windows/backup-files.ps1')))->toContain("'--tag', 'rmm'");
});
