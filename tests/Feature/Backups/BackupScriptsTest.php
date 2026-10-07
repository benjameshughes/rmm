<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\BackupScript;
use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Models\Script;
use Illuminate\Support\Facades\File;

beforeEach(fn () => app(SyncSystemScripts::class)());

function backupScriptFile(string $file): string
{
    return File::get(resource_path("scripts/windows/{$file}"));
}

it('syncs every backup script as an admin Windows backup script with the shared restic code in front', function (BackupScript $backupScript): void {
    $script = Script::findSystem($backupScript->value);

    expect($script->category)->toBe(ScriptCategory::Backup)
        ->and($script->platform)->toBe(ScriptPlatform::Windows)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->timeout_seconds)->toBeLessThanOrEqual(config('commands.ad_hoc.timeout_seconds.max'))
        ->and($script->script_content)->toStartWith(backupScriptFile('shared/restic.ps1'))
        ->and($script->script_content)->toEndWith(backupScriptFile("{$backupScript->value}.ps1"))
        ->and($script->secretNames())->toContain('ResticSha256', 'ResticExeSha256');
})->with(BackupScript::cases());

it('keeps the backup scripts plain ASCII PowerShell 5.1 without try/catch, ending on a JSON line', function (string $file): void {
    $content = backupScriptFile($file);

    expect($content)->not->toMatch('/[^\x00-\x7F]/')
        ->and($content)->not->toMatch('/\btry\s*\{/i')
        ->and($content)->not->toMatch('/\bcatch\s*\{/i')
        ->and($content)->toContain('ConvertTo-Json');
})->with(['shared/restic.ps1', 'install-restic.ps1', 'backup-files.ps1', 'backup-snapshots.ps1', 'backup-restore.ps1']);

it('never carries a secret, server address or pinned value in the script text', function (BackupScript $backupScript): void {
    $content = Script::findSystem($backupScript->value)->script_content;

    expect($content)->not->toContain(config('backup.restic.sha256'))
        ->and($content)->not->toContain(config('backup.restic.exe_sha256'))
        ->and($content)->not->toContain('github.com')
        ->and($content)->not->toMatch('/https?:\/\//')
        ->and($content)->not->toMatch('/RESTIC_PASSWORD\s*=\s*[\'"]/')
        ->and($content)->not->toMatch('/Write-Output[^\r\n]*RMM_ResticPassword/');
})->with(BackupScript::cases());

it('checks the restic download and binary against the pinned sha256 before running it, in a locked folder', function (): void {
    $shared = backupScriptFile('shared/restic.ps1');

    expect($shared)->toContain('(Get-FileSha256 $zip) -eq $resticZipSha256')
        ->toContain('(Get-FileSha256 $exe.FullName) -eq $resticExeSha256')
        ->toContain('(Get-FileSha256 $restic) -eq $resticExeSha256')
        ->toContain('SetAccessRuleProtection($true, $false)')
        ->toContain("'S-1-5-18', 'S-1-5-32-544'")
        ->toContain('ReparsePoint')
        ->toContain('foreach ($attempt in 1..3)')
        ->toContain('$env:RESTIC_REPOSITORY = "rest:$restUrl/$repositoryName/"')
        ->not->toContain('RESTIC_REST_USERNAME')
        ->not->toContain('RESTIC_REST_PASSWORD')
        ->toContain("\$env:GOMAXPROCS = '2'")
        ->toContain("PriorityClass = 'BelowNormal'")
        ->not->toContain('$env:TEMP');
});

it('backs up whole profiles from a shadow copy, skipping caches and huge files', function (): void {
    $backup = backupScriptFile('backup-files.ps1');

    expect($backup)->toContain("'--use-fs-snapshot'")
        ->toContain("'--skip-if-unchanged'")
        ->toContain("'--exclude-caches'")
        ->toContain("'--exclude-cloud-files'")
        ->toContain("'--exclude-larger-than', '4G'")
        ->toContain("'--iexclude-file'")
        ->toContain("'--files-from-verbatim'")
        ->toContain("'--tag', 'rmm'")
        ->toContain("'--limit-upload'")
        ->toContain("-Filter 'Special = FALSE'")
        ->toContain("\$_.SID -like 'S-1-5-21-*'")
        ->toContain("'!AppData\\Local\\Packages\\Microsoft.MicrosoftStickyNotes_8wekyb3d8bbwe\\LocalState'")
        ->toContain("'NTUSER.DAT*'")
        ->toContain("'*.ost'")
        ->toContain("status = 'skipped'")
        ->toContain('Get-ResticExitMeaning');
});

it('explains a missing repository as one the first backup creates', function (): void {
    expect(backupScriptFile('shared/restic.ps1'))->toMatch('/10 \{ return "the repository for .* does not exist on the backup server yet. Back up now creates it" \}/');
});

it('creates the repository when the first backup finds none, then backs up in the same run', function (): void {
    $backup = backupScriptFile('backup-files.ps1');

    expect($backup)->toContain("if ([int]\$run.ExitCode -eq 10) {\n    Initialize-ResticRepository\n    \$run = Invoke-Restic \$arguments\n}")
        ->toContain("\$init = Invoke-Restic (@('init') + \$resticArguments)")
        ->toContain('Created the repository for $env:RMM_RepositoryName on the backup server')
        ->and(strpos($backup, 'Initialize-ResticRepository'."\n"))->toBeLessThan(strpos($backup, '$exitCode = [int]$run.ExitCode'));
});

it('stops with the server message when the repository cannot be created', function (): void {
    expect(backupScriptFile('backup-files.ps1'))
        ->toContain('Stop-Run "could not create the repository for $env:RMM_RepositoryName on the backup server: $((Get-ResticErrors $init.Stderr 3) -join \'; \')" ([int]$init.ExitCode)')
        ->not->toContain('htpasswd');
});

it('restores into a new folder without overwriting anything', function (): void {
    expect(backupScriptFile('backup-restore.ps1'))->toContain("'--overwrite', 'never'")
        ->toContain("'--iinclude'")
        ->toContain('icacls.exe $target /reset /T /C /L /Q')
        ->toContain("C:\\Restore\\$(Get-Date -Format 'yyyyMMdd-HHmm')")
        ->toContain('^(latest|[0-9a-fA-F]{8,64})$');
});

it('lists snapshots of this PC only', function (): void {
    expect(backupScriptFile('backup-snapshots.ps1'))->toContain("@('snapshots', '--host', \$env:COMPUTERNAME, '--json')");
});

it('pins a real restic release by version, zip and exe sha256', function (): void {
    $restic = config('backup.restic');

    expect($restic['version'])->toMatch('/^\d+\.\d+\.\d+$/')
        ->and($restic['download_url'])->toBe("https://github.com/restic/restic/releases/download/v{$restic['version']}/restic_{$restic['version']}_windows_amd64.zip")
        ->and($restic['sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($restic['exe_sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($restic['sha256'])->not->toBe($restic['exe_sha256']);
});
