<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\BackupScript;
use App\Enums\RestoreMode;
use App\Livewire\Devices\RestoreBackup;
use App\Models\Device;
use App\Models\DeviceBackupSnapshot;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->withBackupCredentials()->create(['hostname' => 'OLD-PC', 'agent_version' => '0.7.1', 'last_seen' => now()]);
    $this->newPc = Device::factory()->active()->windows()->withApiKey('NEW-KEY')->create(['hostname' => 'NEW-PC', 'agent_version' => '0.7.1']);
    $this->account = StaffAccount::factory()->create(['username' => 'sophie', 'full_name' => 'Sophie Smith']);
    $this->account->devices()->attach($this->newPc);
    $this->snapshot = DeviceBackupSnapshot::factory()->create(['device_id' => $this->device->id, 'paths' => ['C:\\Users\\SOPHIE.SMITH', 'C:\\Users\\ben']]);
});

function profileRestoreModal(Device $device, User $user, string $snapshotId): Testable
{
    return Livewire::actingAs($user)->test(RestoreBackup::class, ['device' => $device])
        ->call('open', $snapshotId)
        ->set('mode', RestoreMode::Profile->value);
}

it('restores a snapshot into a staff account profile on the PC it targets', function (): void {
    profileRestoreModal($this->device, $this->user, $this->snapshot->snapshot_id)
        ->set('targetDeviceId', $this->newPc->id)
        ->assertSee('sophie')
        ->set('staffAccountId', $this->account->id)
        ->assertSet('sourceProfile', 'C:\\Users\\SOPHIE.SMITH')
        ->call('restore')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $command = DeviceCommand::query()->where('device_id', $this->newPc->id)->sole();

    expect($command->script->slug)->toBe(BackupScript::RestoreProfile->value)
        ->and($command->parameters)->toBe([
            'SnapshotId' => $this->snapshot->snapshot_id,
            'SourceProfile' => 'C:\\Users\\SOPHIE.SMITH',
            'Username' => 'sophie',
            'SourceDevice' => (string) $this->device->id,
        ]);
});

it('leaves the source profile for typing when no snapshot folder looks like the account', function (): void {
    $other = StaffAccount::factory()->create(['username' => 'zed', 'full_name' => 'Zed Brown']);
    $other->devices()->attach($this->newPc);

    profileRestoreModal($this->device, $this->user, $this->snapshot->snapshot_id)
        ->set('targetDeviceId', $this->newPc->id)
        ->set('staffAccountId', $other->id)
        ->assertSet('sourceProfile', '')
        ->call('restore')
        ->assertHasErrors(['sourceProfile' => 'required']);
});

it('refuses a staff account that does not target the restore PC', function (): void {
    $elsewhere = StaffAccount::factory()->create();
    $elsewhere->devices()->attach($this->device);

    profileRestoreModal($this->device, $this->user, 'latest')
        ->set('targetDeviceId', $this->newPc->id)
        ->set('staffAccountId', $elsewhere->id)
        ->set('sourceProfile', 'C:\\Users\\ben')
        ->call('restore')
        ->assertHasErrors(['staffAccountId' => 'in']);

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('refuses a profile restore that is not safe to queue', function (string $field, mixed $value, string $rule): void {
    profileRestoreModal($this->device, $this->user, 'latest')
        ->set('targetDeviceId', $this->newPc->id)
        ->set('staffAccountId', $this->account->id)
        ->set('sourceProfile', 'C:\\Users\\SOPHIE.SMITH')
        ->set($field, $value)
        ->call('restore')
        ->assertHasErrors([$field => $rule]);

    expect(DeviceCommand::query()->count())->toBe(0);
})->with([
    'no staff account' => ['staffAccountId', null, 'required'],
    'a relative source profile' => ['sourceProfile', 'Users\\sophie', 'regex'],
    'a source profile with a quote' => ['sourceProfile', 'C:\\Users\\"x', 'regex'],
    'an unknown mode' => ['mode', 'elsewhere', 'Illuminate\\Validation\\Rules\\Enum'],
]);

it('keeps the folder restore exactly as it was', function (): void {
    Livewire::actingAs($this->user)->test(RestoreBackup::class, ['device' => $this->device])
        ->call('open', 'latest')
        ->assertSet('mode', RestoreMode::Folder->value)
        ->set('target', 'D:\\Restore\\Old')
        ->call('restore')
        ->assertHasNoErrors();

    $command = DeviceCommand::query()->sole();

    expect($command->script->slug)->toBe(BackupScript::Restore->value)
        ->and($command->parameters)->toBe([
            'SnapshotId' => 'latest',
            'Target' => 'D:\\Restore\\Old',
            'SourceDevice' => (string) $this->device->id,
        ]);
});

it('syncs backup-restore-profile with the profile folders as a secret', function (): void {
    $script = Script::findSystem(BackupScript::RestoreProfile->value);

    expect($script->is_internal)->toBeTrue()
        ->and($script->parameters->pluck('name')->all())->toBe(['SnapshotId', 'SourceProfile', 'Username', 'SourceDevice'])
        ->and($script->secretNames())->toContain('RestoreProfileFolders', 'RepositoryName', 'ResticPassword')
        ->and($script->script_content)->toContain('ProfileList')
        ->toContain('/XC', '/XN', '/XO')
        ->toContain('/setowner')
        ->toContain('$env:RMM_RestoreProfileFolders');
});

it('hands the profile restore the folder list and the source PC credentials', function (): void {
    $this->actingAs($this->user);

    profileRestoreModal($this->device, $this->user, 'latest')
        ->set('targetDeviceId', $this->newPc->id)
        ->set('staffAccountId', $this->account->id)
        ->set('sourceProfile', 'C:\\Users\\SOPHIE.SMITH')
        ->call('restore');

    $parameters = $this->withHeaders(['X-Agent-Key' => 'NEW-KEY'])->getJson('/api/commands/pending')->assertSuccessful()->json('command.parameters');

    expect(json_decode($parameters['RestoreProfileFolders'], true))->toBe(config('backup.restore_profile.folders'))
        ->and($parameters['RepositoryName'])->toBe($this->device->backup_repository_name);
});

it('pre-creates the profile when syncing a local user', function (): void {
    expect(File::get(resource_path('scripts/windows/sync-local-user.ps1')))
        ->toContain('CreateProfile(')
        ->toContain('0x800700B7')
        ->toContain('ProfileList');
});
