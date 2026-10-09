<?php

declare(strict_types=1);

use App\Actions\Script\ResolveCommandSecrets;
use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use Illuminate\Support\Facades\File;

function deletePathScriptFile(string $file): string
{
    return File::get(resource_path("scripts/windows/{$file}"));
}

it('syncs delete, purge and restore as admin maintenance scripts with the shared include first', function (string $slug, string $file, array $parameters, array $secrets): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem($slug);

    expect($script)
        ->category->toBe(ScriptCategory::Maintenance)
        ->requires_admin->toBeTrue()
        ->platform->value->toBe('windows')
        ->script_content->toBe(deletePathScriptFile('shared/remove-path.ps1')."\n".deletePathScriptFile($file))
        ->and($script->parameters->pluck('name')->all())->toBe($parameters)
        ->and($script->secretNames())->toBe($secrets);
})->with([
    'delete' => ['remove-path', 'remove-path.ps1', ['Path', 'Mode', 'ExpectedKind'], ['DeletePathProtected']],
    'purge' => ['purge-quarantine', 'purge-quarantine.ps1', ['Days', 'Folder'], []],
    'restore' => ['restore-quarantine', 'restore-quarantine.ps1', ['Folder', 'Path'], ['DeletePathProtected']],
]);

it('is plain ASCII PowerShell 5.1 with no try blocks and never a recursive Remove-Item', function (string $file): void {
    $code = preg_replace('/^\s*#.*$/m', '', deletePathScriptFile($file));

    expect(deletePathScriptFile($file))->not->toMatch('/[^\x00-\x7F]/')
        ->and($code)
        ->not->toMatch('/\btry\s*\{/')
        ->not->toMatch('/\bcatch\b/')
        ->not->toMatch('/-Recurse\b/i')
        ->not->toMatch('/\brmdir\b.*\/s/i');
})->with(['shared/remove-path.ps1', 'remove-path.ps1', 'purge-quarantine.ps1', 'restore-quarantine.ps1']);

it('walks folders one level at a time and removes links as links, never entering them', function (): void {
    expect(deletePathScriptFile('shared/remove-path.ps1'))
        ->toContain('[IO.FileAttributes]::ReparsePoint')
        ->toContain('Get-ChildItem -LiteralPath $current -Force -ErrorAction SilentlyContinue')
        ->toContain('[System.IO.Directory]::Delete($path, $false)')
        ->toContain('if (Test-IsLink $child) {')
        ->toContain('$contents.Links.Add($child)')
        ->toContain('function Get-LinkedAncestor')
        ->toContain('$maxFailuresShown = 20')
        ->toContain('-ErrorVariable removeErrors')
        ->and(deletePathScriptFile('remove-path.ps1'))
        ->toContain('$linkedAncestor = Get-LinkedAncestor $path')
        ->toContain('if (-not (Remove-Link $item))');
});

it('reads its parameters and the protected list from the environment and checks them again on the PC', function (): void {
    expect(deletePathScriptFile('remove-path.ps1'))
        ->toContain('$env:RMM_Path')
        ->toContain('$env:RMM_Mode')
        ->toContain('$env:RMM_ExpectedKind')
        ->toContain('$refusal = Get-PathRefusal $path')
        ->toContain('Stop-Removal "$path is a $kind, not a $expectedKind. Nothing was deleted"')
        ->and(deletePathScriptFile('shared/remove-path.ps1'))
        ->toContain('$env:RMM_DeletePathProtected')
        ->toContain('the protected path list did not arrive from the server')
        ->toContain('$env:SystemRoot, $env:ProgramFiles, ${env:ProgramFiles(x86)}, $rmmDir')
        ->and(deletePathScriptFile('purge-quarantine.ps1'))
        ->toContain('$env:RMM_Days')
        ->toContain('$env:RMM_Folder')
        ->and(deletePathScriptFile('restore-quarantine.ps1'))
        ->toContain('$env:RMM_Folder')
        ->toContain('$env:RMM_Path')
        ->toContain('exists again. Move or delete what is there first');
});

it('quarantines by moving within the system drive only, and locks the quarantine folder', function (): void {
    expect(deletePathScriptFile('remove-path.ps1'))
        ->toContain('Move-Item -LiteralPath $path -Destination $target')
        ->toContain('quarantine only works on the system drive')
        ->toContain("ToString('yyyyMMddTHHmmssZ')")
        ->not->toContain('Copy-Item')
        ->and(deletePathScriptFile('shared/remove-path.ps1'))
        ->toContain("\$quarantineRoot = Join-Path \$rmmDir 'Quarantine'")
        ->toContain('function Lock-QuarantineRoot')
        ->toContain("\$quarantineFolderPattern = '^\\d{8}T\\d{6}Z-[0-9a-f]{8}\$'");

    expect(preg_match(config('devices.delete_path.quarantine_folder_pattern'), '20261009T101500Z-0a1b2c3d'))->toBe(1);
});

it('ends every script with the JSON line schema the server reads', function (string $file, string $schemaKey): void {
    expect(deletePathScriptFile($file))
        ->toContain("schema = '".config("devices.delete_path.{$schemaKey}")."'")
        ->toMatch('/Write-Output \(ConvertTo-Json -InputObject \$result -Compress[^)]*\)\s*(\n\s*\n\s*if \(\$failed\.Count -gt 0\) \{\s*exit 1\s*\}\s*)?\s*exit 0\s*$/');
})->with([
    ['remove-path.ps1', 'schema'],
    ['purge-quarantine.ps1', 'purge_schema'],
    ['restore-quarantine.ps1', 'restore_schema'],
]);

it('hands the protected paths to the scripts as JSON from config', function (): void {
    app(SyncSystemScripts::class)();
    $device = Device::factory()->active()->windows()->create(['agent_version' => '0.9.0']);
    $command = DeviceCommand::factory()->create(['device_id' => $device->id, 'script_id' => Script::findSystem('remove-path')->id]);

    $protected = json_decode(app(ResolveCommandSecrets::class)($command)['DeletePathProtected'], true);

    expect($protected)->toBe(config('devices.delete_path.protected'))
        ->and($protected['trees'])->toContain('Windows', 'Program Files', 'ProgramData\\BenJH RMM', 'ProgramData\\RMM')
        ->and($protected['exact'])->toContain('Users\\*', '$Recycle.Bin');
});
