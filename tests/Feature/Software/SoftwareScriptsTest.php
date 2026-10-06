<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

pest()->use(RefreshDatabase::class);

beforeEach(fn () => app(SyncSystemScripts::class)());

function softwareScript(string $slug): string
{
    return File::get(resource_path("scripts/windows/{$slug}.ps1"));
}

it('syncs the inventory, upgrade and uninstall scripts', function (string $slug, bool $takesPackageId): void {
    $script = Script::findSystem($slug);

    expect($script->category)->toBe(ScriptCategory::Updates)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->platform->value)->toBe('windows')
        ->and($script->parameters->pluck('name')->all())->toBe($takesPackageId ? ['PackageId'] : []);
})->with([
    'inventory' => ['winget-inventory', false],
    'upgrade' => ['winget-upgrade', true],
    'uninstall' => ['winget-uninstall', true],
]);

it('keeps the software scripts plain ASCII for Windows PowerShell 5.1', function (string $slug): void {
    expect(softwareScript($slug))->not->toMatch('/[^\x00-\x7F]/');
})->with(['winget-inventory', 'winget-upgrade', 'winget-uninstall']);

it('prints the inventory as one compact JSON array line with the agreed fields', function (): void {
    expect(softwareScript('winget-inventory'))
        ->toContain('Get-WinGetPackage')
        ->toContain('Find-WinGet')
        ->toContain('Install-Module -Name Microsoft.WinGet.Client')
        ->toContain('ConvertTo-Json -InputObject $_ -Compress')
        ->toContain("Write-Output ('[' + (\$items -join ',') + ']')")
        ->toContain('installed_version')
        ->toContain('latest_version')
        ->toContain('is_update_available')
        ->toContain("Write-Output 'ATTENTION:")
        ->not->toContain('catch');
});

it('reads the package ID only from the environment and runs winget exactly and silently', function (string $slug, string $command): void {
    expect(softwareScript($slug))
        ->toContain('$env:RMM_PackageId')
        ->toContain($command)
        ->toContain('Write-Output "OK:')
        ->toContain('Write-Output "ATTENTION:');
})->with([
    'upgrade' => ['winget-upgrade', 'upgrade --id $packageId --exact --scope machine --silent --accept-package-agreements --accept-source-agreements --disable-interactivity'],
    'uninstall' => ['winget-uninstall', 'uninstall --id $packageId --exact --silent --accept-source-agreements --disable-interactivity'],
]);

it('accepts every inventory ID shape in the uninstall script but refuses quotes and control characters', function (string $packageId, bool $isAccepted): void {
    preg_match("/-notmatch '([^']+)'/", file_get_contents(resource_path('scripts/windows/winget-uninstall.ps1')), $match);

    expect((bool) preg_match('/'.$match[1].'/', $packageId))->toBe($isAccepted);
})->with([
    'winget id' => ['Mozilla.Firefox', true],
    'arp id with spaces' => ['ARP\Machine\X86\Microsoft Copilot', true],
    'arp id with brackets' => ['ARP\Machine\X64\Microsoft Visual C++ 2015-2022 Redistributable (x64) - 14.40.33810', true],
    'msix id' => ['MSIX\Microsoft.WindowsCalculator_11.2405.2.0_x64__8wekyb3d8bbwe', true],
    'arp guid id' => ['ARP\Machine\X64\{2BD7D1F1-1A23-4F5B-9E36-1E6F3A3C0D2A}', true],
    'double quote' => ['Mozilla.Firefox" --force', false],
    'newline' => ["Mozilla.Firefox\n--force", false],
    'leading dash' => ['--force', false],
]);

it('installs the WinGet module with PSResourceGet under PowerShell 7, keeping Install-Module only as the fallback', function (): void {
    $script = file_get_contents(resource_path('scripts/windows/winget-inventory.ps1'));

    $modern = strpos($script, 'Install-PSResource -Name Microsoft.WinGet.Client');

    expect($modern)->not->toBeFalse()
        ->and(strpos($script, 'Install-Module -Name Microsoft.WinGet.Client'))->toBeGreaterThan($modern)
        ->and($script)->toContain('if (Get-Command Install-PSResource -ErrorAction SilentlyContinue)');
});
