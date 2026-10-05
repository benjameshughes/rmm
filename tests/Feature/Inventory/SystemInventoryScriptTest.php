<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

pest()->use(RefreshDatabase::class);

function systemInventoryScript(): string
{
    return File::get(resource_path('scripts/windows/system-inventory.ps1'));
}

it('syncs the system inventory as a read-only windows info script run as admin', function (): void {
    app(SyncSystemScripts::class)();

    expect(Script::findSystem('system-inventory'))
        ->name->toBe('System Inventory')
        ->category->toBe(ScriptCategory::Info)
        ->requires_admin->toBeTrue()
        ->platform->value->toBe('windows')
        ->timeout_seconds->toBe(600)
        ->script_content->toBe(systemInventoryScript());
});

it('keeps the script plain ASCII for Windows PowerShell 5.1', function (): void {
    expect(systemInventoryScript())->not->toMatch('/[^\x00-\x7F]/');
});

it('prints one compact JSON line with every agreed section and flags total failure', function (): void {
    expect(systemInventoryScript())
        ->toContain('ConvertTo-Json -InputObject $inventory -Depth 6 -Compress')
        ->toContain("Write-Output 'ATTENTION:")
        ->toContain('exit 1')
        ->toContain('Remove-TypeData -TypeName System.Array')
        ->toContain('Get-LocalGroupMember -SID $administratorsSid')
        ->toContain("'S-1-5-32-544'")
        ->toContain('MSFT_PhysicalDisk')
        ->toContain('WmiMonitorID')
        ->toContain('Win32_EncryptableVolume')
        ->not->toContain('catch');

    collect(['system', 'cpu', 'memory', 'disks', 'volumes', 'gpus', 'monitors', 'printers', 'usb_devices', 'input_devices', 'network_adapters', 'battery', 'windows', 'security', 'users', 'updates', 'software_environment', 'drivers', 'startup', 'services_non_microsoft', 'scheduled_tasks_non_microsoft', 'collected_at'])
        ->each(fn (string $section) => expect(systemInventoryScript())->toContain("    {$section} = "));
});

it('never reads product keys, BitLocker recovery secrets or Win32_Product', function (): void {
    expect(systemInventoryScript())
        ->not->toContain('OA3xOriginalProductKey')
        ->not->toMatch('/\bProductKey\b/')
        ->not->toContain('.PartialProductKey')
        ->not->toContain('RecoveryPassword')
        ->not->toContain('GetKeyProtectorNumericalPassword')
        ->not->toContain('GetKeyProtectors')
        ->not->toContain('Win32_Product');
});

it('changes nothing on the device', function (): void {
    expect(systemInventoryScript())->not->toMatch('/\b(Set-|New-Item|Remove-Item|Enable-|Disable-|Start-Service|Stop-Service|Restart-|Invoke-WmiMethod)/i');
});
