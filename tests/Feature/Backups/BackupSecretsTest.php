<?php

declare(strict_types=1);

use App\Actions\Backup\QueueBackupScript;
use App\Actions\Script\ResolveCommandSecrets;
use App\Actions\Script\SyncSystemScripts;
use App\Enums\BackupScript;
use App\Enums\CommandStatus;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    config([
        'backup.rest_url' => 'https://scarif.example.test:30248/',
        'backup.ca_cert' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----",
    ]);

    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->withBackupCredentials()->withApiKey('BACKUP-PC-KEY')->create(['agent_version' => '0.7.1']);
});

function pullCommand(string $apiKey = 'BACKUP-PC-KEY'): array
{
    return test()->withHeaders(['X-Agent-Key' => $apiKey])->getJson('/api/commands/pending')->assertSuccessful()->json('command');
}

/**
 * Every value stored anywhere in the database, as one string to search.
 */
function everythingStored(): string
{
    return collect(['device_commands', 'audit_logs', 'device_backups', 'scripts'])
        ->map(fn (string $table): string => DB::table($table)->get()->toJson())
        ->implode("\n");
}

it('hands the agent the repository name and password, server and restic pin only when it fetches a backup', function (): void {
    $this->actingAs($this->user);
    app(QueueBackupScript::class)(BackupScript::BackUp, $this->device, $this->user, ['StaggerMinutes' => 0]);

    $parameters = pullCommand()['parameters'];

    expect($parameters)->toBe([
        'StaggerMinutes' => '0',
        'UploadLimitKiB' => '0',
        'RestUrl' => 'https://scarif.example.test:30248',
        'RestCaCert' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----",
        'RepositoryName' => $this->device->backup_repository_name,
        'ResticPassword' => $this->device->backup_repository_password,
        'ResticVersion' => config('backup.restic.version'),
        'ResticDownloadUrl' => config('backup.restic.download_url'),
        'ResticSha256' => config('backup.restic.sha256'),
        'ResticExeSha256' => config('backup.restic.exe_sha256'),
        'MasterPassword' => '',
    ]);
});

it('never stores or audits the secrets: not in the command, its parameters, the audit log or the logs', function (): void {
    $logged = collect();
    Event::listen(MessageLogged::class, fn (MessageLogged $message) => $logged->push($message->message.json_encode($message->context)));
    $this->actingAs($this->user);

    $command = app(QueueBackupScript::class)(BackupScript::BackUp, $this->device, $this->user, ['StaggerMinutes' => 0]);
    pullCommand();

    $stored = everythingStored();

    expect($command->fresh()->parameters)->toBe(['StaggerMinutes' => '0', 'UploadLimitKiB' => '0'])
        ->and($command->fresh()->status)->toBe(CommandStatus::Sent)
        ->and(AuditLog::query()->count())->toBeGreaterThan(0)
        ->and($stored)->not->toContain($this->device->backup_repository_password)
        ->and($stored)->not->toContain('scarif.example.test')
        ->and($logged)->not->toBeEmpty()
        ->and($logged->implode("\n"))->not->toContain($this->device->backup_repository_password);
});

it('stores the repository password encrypted and keeps it out of serialised devices', function (): void {
    $raw = DB::table('devices')->where('id', $this->device->id)->first();

    expect($raw->backup_repository_password)->not->toContain('repo-secret')
        ->and(decrypt($raw->backup_repository_password, unserialize: false))->toBe($this->device->backup_repository_password)
        ->and($this->device->toArray())->not->toHaveKey('backup_repository_password');
});

it('hands a restore the source device credentials when SourceDevice names another Windows PC', function (): void {
    $target = Device::factory()->active()->windows()->withApiKey('TARGET-KEY')->create(['agent_version' => '0.7.1']);
    $this->actingAs($this->user);

    app(QueueBackupScript::class)(BackupScript::Restore, $target, $this->user, ['SnapshotId' => 'latest', 'SourceDevice' => $this->device->id]);

    $parameters = pullCommand('TARGET-KEY')['parameters'];

    expect($parameters['SourceDevice'])->toBe((string) $this->device->id)
        ->and($parameters['RepositoryName'])->toBe($this->device->backup_repository_name)
        ->and($parameters['ResticPassword'])->toBe($this->device->backup_repository_password);
});

it('defaults a restore to the device it runs on', function (): void {
    $this->actingAs($this->user);
    app(QueueBackupScript::class)(BackupScript::Restore, $this->device, $this->user, ['SnapshotId' => 'latest']);

    expect(pullCommand()['parameters']['RepositoryName'])->toBe($this->device->backup_repository_name);
});

it('hands over blank credentials when the source is not a Windows PC with credentials', function (string $source): void {
    $sourceId = match ($source) {
        'without credentials' => Device::factory()->active()->windows()->create()->id,
        'linux' => Device::factory()->active()->linux()->create(['backup_repository_name' => 'srv', 'backup_repository_password' => 'y', 'backup_configured_at' => now()])->id,
        'missing' => 999999,
    };

    $target = Device::factory()->active()->windows()->withApiKey('TARGET-KEY')->create(['agent_version' => '0.7.1']);
    $command = DeviceCommand::factory()->create([
        'device_id' => $target->id,
        'script_id' => Script::findSystem(BackupScript::Restore->value)->id,
        'status' => CommandStatus::Pending,
        'parameters' => ['SnapshotId' => 'latest', 'SourceDevice' => (string) $sourceId],
    ]);

    $parameters = pullCommand('TARGET-KEY')['parameters'];

    expect($parameters['RepositoryName'])->toBe('')
        ->and($parameters['ResticPassword'])->toBe('')
        ->and($parameters['RestUrl'])->toBe('https://scarif.example.test:30248')
        ->and($command->fresh()->status)->toBe(CommandStatus::Sent);
})->with(['without credentials', 'linux', 'missing']);

it('ignores SourceDevice on scripts that do not declare it', function (): void {
    $other = Device::factory()->active()->withBackupCredentials()->create();
    DeviceCommand::factory()->create([
        'device_id' => $this->device->id,
        'script_id' => Script::findSystem(BackupScript::BackUp->value)->id,
        'status' => CommandStatus::Pending,
        'parameters' => ['SourceDevice' => (string) $other->id],
    ]);

    expect(pullCommand()['parameters']['RepositoryName'])->toBe($this->device->backup_repository_name);
});

it('never hands secrets to user scripts, ad-hoc commands or non-Windows devices', function (): void {
    $userScript = Script::factory()->create(['is_system' => false, 'slug' => null]);
    DeviceCommand::factory()->create(['device_id' => $this->device->id, 'script_id' => $userScript->id, 'status' => CommandStatus::Pending, 'queued_at' => now()->subMinute()]);
    DeviceCommand::factory()->create(['device_id' => $this->device->id, 'script_id' => null, 'script_content' => 'Get-Date', 'status' => CommandStatus::Pending, 'queued_at' => now()]);

    $linux = Device::factory()->active()->linux()->withApiKey('LINUX-KEY')->create(['backup_repository_name' => 'srv', 'backup_repository_password' => 'linux-repo', 'backup_configured_at' => now()]);
    DeviceCommand::factory()->create(['device_id' => $linux->id, 'script_id' => Script::findSystem(BackupScript::BackUp->value)->id, 'status' => CommandStatus::Pending]);

    expect(pullCommand()['parameters'])->toBe([])
        ->and(pullCommand()['parameters'])->toBe([])
        ->and(pullCommand('LINUX-KEY')['parameters'])->toBe([]);
});

it('fills every secret a system script declares, none of them shadowing one of its parameters', function (): void {
    collect(config('scripts.system'))
        ->filter(fn (array $definition): bool => isset($definition['secrets']))
        ->each(function (array $definition, string $slug): void {
            $command = DeviceCommand::factory()->create(['device_id' => $this->device->id, 'script_id' => Script::findSystem($slug)->id]);

            expect(array_keys(app(ResolveCommandSecrets::class)($command)))->toBe($definition['secrets'])
                ->and(collect($definition['secrets'])->intersect(collect($definition['parameters'] ?? [])->pluck('name')))->toBeEmpty();
        });
});

it('refuses to queue a backup script on an agent too old to pass parameters on', function (): void {
    $old = Device::factory()->active()->withBackupCredentials()->create(['agent_version' => '0.5.0']);
    $this->actingAs($this->user);

    app(QueueBackupScript::class)(BackupScript::InstallRestic, $old, $this->user);
})->throws(ValidationException::class);
