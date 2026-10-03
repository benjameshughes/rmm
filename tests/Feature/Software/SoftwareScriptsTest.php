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
