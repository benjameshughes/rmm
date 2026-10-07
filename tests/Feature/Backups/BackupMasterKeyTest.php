<?php

declare(strict_types=1);

use App\Actions\Backup\QueueBackupScript;
use App\Actions\Script\ResolveCommandSecrets;
use App\Actions\Script\SyncSystemScripts;
use App\Enums\BackupScript;
use App\Enums\CommandStatus;
use App\Livewire\Devices\BackupCredentials;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    config(['backup.rest_url' => 'https://scarif.example.test:30248', 'backup.master_password' => 'master-secret-Xq7Lp2']);

    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->withBackupCredentials()->withApiKey('MASTER-PC-KEY')->create(['agent_version' => '0.7.1']);
});

function masterKeyCommand(Device $device, BackupScript $script = BackupScript::BackUp, CommandStatus $status = CommandStatus::Pending): DeviceCommand
{
    return DeviceCommand::factory()->create([
        'device_id' => $device->id,
        'script_id' => Script::findSystem($script->value)->id,
        'status' => $status,
        'sent_at' => $status === CommandStatus::Pending ? null : now(),
        'started_at' => $status === CommandStatus::Pending ? null : now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function masterKeyBackupOutput(array $overrides = []): string
{
    return "OK: backed up 1 user profiles\r\n".json_encode([
        'status' => 'ok',
        'exit_code' => 0,
        'snapshot_id' => str_repeat('ab12', 16),
        'errors' => [],
        'sources' => ['C:\\Users\\anna'],
        ...$overrides,
    ])."\r\n";
}

it('hands backup-files the master password while the PC master key is pending', function (): void {
    $secrets = app(ResolveCommandSecrets::class)(masterKeyCommand($this->device));

    expect($secrets['MasterPassword'])->toBe('master-secret-Xq7Lp2');
});

it('sends the master password blank once the PC is stamped, or when none is configured', function (string $case): void {
    match ($case) {
        'stamped' => $this->device->forceFill(['backup_master_key_added_at' => now()])->save(),
        'not configured' => config(['backup.master_password' => null]),
        'blank' => config(['backup.master_password' => '']),
    };

    expect(app(ResolveCommandSecrets::class)(masterKeyCommand($this->device))['MasterPassword'])->toBe('');
})->with(['stamped', 'not configured', 'blank']);

it('sends the master password blank to a PC without backups enabled', function (): void {
    $bare = Device::factory()->active()->windows()->create();

    expect(app(ResolveCommandSecrets::class)(masterKeyCommand($bare))['MasterPassword'])->toBe('');
});

it('never hands the master password to any other script', function (BackupScript $script): void {
    $secrets = app(ResolveCommandSecrets::class)(masterKeyCommand($this->device, $script));

    expect($secrets)->not->toHaveKey('MasterPassword')
        ->and($secrets)->not->toContain('master-secret-Xq7Lp2');
})->with(fn (): array => collect(BackupScript::cases())->reject(fn (BackupScript $script): bool => $script === BackupScript::BackUp)->all());

it('declares the master password on backup-files only', function (): void {
    $declaring = collect(config('scripts.system'))
        ->filter(fn (array $definition): bool => in_array('MasterPassword', $definition['secrets'] ?? [], true))
        ->keys()
        ->all();

    expect($declaring)->toBe([BackupScript::BackUp->value]);
});

it('never stores, audits or logs the master password', function (): void {
    $logged = collect();
    Event::listen(MessageLogged::class, fn (MessageLogged $message) => $logged->push($message->message.json_encode($message->context)));
    $this->actingAs($this->user);

    $command = app(QueueBackupScript::class)(BackupScript::BackUp, $this->device, $this->user, ['StaggerMinutes' => 0]);
    $pulled = $this->withHeaders(['X-Agent-Key' => 'MASTER-PC-KEY'])->getJson('/api/commands/pending')->assertSuccessful()->json('command');

    $stored = collect(['device_commands', 'audit_logs', 'devices', 'scripts'])
        ->map(fn (string $table): string => DB::table($table)->get()->toJson())
        ->implode("\n");

    expect($pulled['parameters']['MasterPassword'])->toBe('master-secret-Xq7Lp2')
        ->and($command->fresh()->parameters)->toBe(['StaggerMinutes' => '0', 'UploadLimitKiB' => '0'])
        ->and($stored)->not->toContain('master-secret-Xq7Lp2')
        ->and($logged->implode("\n"))->not->toContain('master-secret-Xq7Lp2');
});

it('stamps the PC when the backup result says the master key was added or already present', function (string $outcome): void {
    $this->travelTo(now()->startOfMinute());

    masterKeyCommand($this->device, status: CommandStatus::Running)->markAsCompleted(masterKeyBackupOutput(['master_key' => $outcome]), 0);

    expect($this->device->fresh()->backup_master_key_added_at?->toDateTimeString())->toBe(now()->toDateTimeString());
})->with(['added', 'present']);

it('leaves the PC pending when the master key failed, was not requested or is missing from the result', function (?string $outcome): void {
    $overrides = $outcome === null ? [] : ['master_key' => $outcome];

    masterKeyCommand($this->device, status: CommandStatus::Running)->markAsCompleted(masterKeyBackupOutput($overrides), 0);

    expect($this->device->fresh()->backup_master_key_added_at)->toBeNull()
        ->and($this->device->fresh()->last_good_backup_at)->not->toBeNull();
})->with(['failed', 'not_requested', null]);

it('stamps the master key only once', function (): void {
    $firstAddedAt = now()->subDays(5)->startOfSecond();
    $this->device->forceFill(['backup_master_key_added_at' => $firstAddedAt])->save();

    masterKeyCommand($this->device, status: CommandStatus::Running)->markAsCompleted(masterKeyBackupOutput(['master_key' => 'added']), 0);

    expect($this->device->fresh()->backup_master_key_added_at->toDateTimeString())->toBe($firstAddedAt->toDateTimeString());
});

it('shows the master key status on the Backups tab', function (string $case, string $expected): void {
    match ($case) {
        'added' => $this->device->forceFill(['backup_master_key_added_at' => '2026-10-07 09:30:00'])->save(),
        'pending' => null,
        'not configured' => config(['backup.master_password' => null]),
    };

    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $this->device])
        ->assertSee('data-master-key-status', false)
        ->assertSee($expected)
        ->assertDontSee('master-secret-Xq7Lp2');
})->with([
    ['added', 'Master key: added 7 Oct 2026'],
    ['pending', 'Master key: pending (added on next backup)'],
    ['not configured', 'Master key: not configured (set BACKUP_MASTER_PASSWORD)'],
]);

it('adds the master key after a good backup with --new-password-file, deleting the file and never printing the master', function (): void {
    $shared = File::get(resource_path('scripts/windows/shared/restic.ps1'));
    $backup = File::get(resource_path('scripts/windows/backup-files.ps1'));

    expect($shared)
        ->toContain("@('cat', 'config', '--no-lock')")
        ->toContain('[int]$check.ExitCode -ne 12')
        ->toContain("\$masterKeyFile = Join-Path \$resticDir 'master-key.txt'")
        ->toContain("@('key', 'add', '--new-password-file', \$masterKeyFile")
        ->toContain('Remove-Item -LiteralPath $masterKeyFile -Force')
        ->toContain('$env:RESTIC_PASSWORD = $env:RMM_ResticPassword')
        ->not->toMatch('/Write-(Output|Host)[^\r\n]*MasterPassword/')
        ->and($backup)
        ->toContain('if ($exitCode -eq 0 -or $exitCode -eq 3) {')
        ->toContain('$masterKey = Add-ResticMasterKey')
        ->toContain('master_key = $masterKey.Status')
        ->toContain('ATTENTION: the backup itself is fine')
        ->toEndWith("exit \$exitCode\n")
        ->not->toContain('RMM_MasterPassword');

    expect(strpos($shared, 'Remove-Item -LiteralPath $masterKeyFile'))->toBeGreaterThan(strpos($shared, "@('key', 'add'"));
});
