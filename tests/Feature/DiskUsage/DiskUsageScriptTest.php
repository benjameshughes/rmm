<?php

declare(strict_types=1);

use App\Actions\Script\ResolveCommandSecrets;
use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use Illuminate\Support\Facades\File;

function diskUsageScript(): string
{
    return File::get(resource_path('scripts/windows/disk-usage.ps1'));
}

it('syncs the disk scan as a windows maintenance script run as admin with a path and depth', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('disk-usage');

    expect($script)
        ->category->toBe(ScriptCategory::Maintenance)
        ->requires_admin->toBeTrue()
        ->platform->value->toBe('windows')
        ->timeout_seconds->toBe(900)
        ->script_content->toBe(diskUsageScript())
        ->and($script->parameters->pluck('default', 'name')->all())->toBe(['Path' => 'C:\\', 'Depth' => '4'])
        ->and($script->secretNames())->toBe(['DiskUsageKeep'])
        ->and(Script::findSystem('linux-disk-usage')->platform->value)->toBe('linux');
});

it('runs the agent found through its service like update-agent, never from PATH', function (): void {
    $updateAgent = File::get(resource_path('scripts/windows/update-agent.ps1'));

    expect($updateAgent)->toContain('$exe = (Get-CimInstance Win32_Service -Filter "Name=\'BenJHRMM\'").PathName.Trim(\'"\')')
        ->and(diskUsageScript())
        ->toContain('Get-CimInstance Win32_Service -Filter "Name=\'BenJHRMM\'"')
        ->toContain('$exe = $service.PathName.Trim(\'"\')')
        ->toContain("\$arguments = @('du', \$path, '--json', '--depth', \"\$depth\") + \$keep")
        ->toContain('& $exe @arguments')
        ->toContain("'--keep'; \$_")
        ->toContain('$env:RMM_Path')
        ->toContain('$env:RMM_Depth')
        ->toContain('$env:RMM_DiskUsageKeep')
        ->not->toContain('Get-Command rmm');
});

it('tells an agent too old for disk scans apart and fails it with ATTENTION', function (): void {
    expect(diskUsageScript())
        ->toContain('if ($exitCode -eq 2) {')
        ->toContain("Write-Output 'ATTENTION: this agent is too old for disk scans; update the agent'")
        ->toMatch('/too old for disk scans; update the agent\'\s+exit 1/');
});

it('passes a partial scan through, exit 1 from rmm du, and fails anything else without JSON', function (): void {
    expect(diskUsageScript())
        ->toContain('if (-not $json -or $exitCode -gt 1) {')
        ->toContain("\$partial = if (\$exitCode -eq 1) { ' (partial scan)' } else { '' }");
});

it('prints OK summary lines before passing the JSON line through last', function (): void {
    expect(diskUsageScript())
        ->toMatch('/Write-Output "OK: scanned.*\n.*Write-Output "OK: .*\n.*Write-Output \$json\.Trim\(\)\s*exit 0\s*$/');
});

it('refuses paths that are not a drive or folder and depths outside 1 to 6', function (): void {
    expect(diskUsageScript())
        ->toContain("'^[A-Za-z]:\\\\[^\"*?<>|\\x00-\\x1F]*$'")
        ->toContain('$depth -lt 1 -or $depth -gt 6');

    collect(['C:\\', 'D:\\', 'C:\\Users\\anna', 'C:\\Program Files (x86)'])
        ->each(fn (string $path) => expect(preg_match(config('disk_usage.path_pattern'), $path))->toBe(1));

    collect(['C:', 'C:Users', '\\\\server\\share', 'C:\\a"b', "C:\\a\nb", 'C:\\a|b', '../etc', 'C:\\*'])
        ->each(fn (string $path) => expect(preg_match(config('disk_usage.path_pattern'), $path))->toBe(0));
});

it('is plain ASCII for Windows PowerShell 5.1 and has no try blocks', function (): void {
    expect(diskUsageScript())
        ->not->toMatch('/[^\x00-\x7F]/')
        ->not->toContain('try {')
        ->not->toMatch('/\b(Set-|New-Item|Remove-Item|Restart-|Stop-)/i');
});

it('hands the space-hog folders to the scan as one value from config', function (): void {
    app(SyncSystemScripts::class)();
    $device = Device::factory()->active()->windows()->create(['agent_version' => '0.9.0']);
    $command = DeviceCommand::factory()->create(['device_id' => $device->id, 'script_id' => Script::findSystem('disk-usage')->id]);

    $keep = explode('|', app(ResolveCommandSecrets::class)($command)['DiskUsageKeep']);

    expect($keep)
        ->toContain('Users\\*\\AppData\\Local\\Temp')
        ->toContain('Windows\\SoftwareDistribution\\Download')
        ->toContain('Users\\*\\AppData\\Local\\Microsoft\\Outlook')
        ->toContain('$Recycle.Bin\\*')
        ->toContain('Windows.old')
        ->toContain('$WINDOWS.~BT')
        ->toContain('ProgramData\\Microsoft\\Windows\\WER')
        ->toContain('Windows\\Installer')
        ->toContain('System Volume Information')
        ->toContain('Users\\*\\Downloads')
        ->toContain('Users\\*\\AppData\\Local\\Google\\Chrome\\User Data\\*\\Cache')
        ->toContain('Users\\*\\AppData\\Local\\Microsoft\\Edge\\User Data\\*\\Cache')
        ->and($keep)->toBe(array_values(array_unique($keep)));
});
