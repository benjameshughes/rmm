<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

pest()->use(RefreshDatabase::class);

function debloatScript(): string
{
    return File::get(resource_path('scripts/windows/debloat-windows.ps1'));
}

function debloatList(string $variable): string
{
    preg_match('/^\$'.$variable.' = @\((.*?)^\)/ms', debloatScript(), $matches);

    return $matches[1] ?? '';
}

it('syncs debloat-windows as an admin maintenance script for Windows', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('debloat-windows');

    expect($script->name)->toBe('Debloat Windows')
        ->and($script->category)->toBe(ScriptCategory::Maintenance)
        ->and($script->platform)->toBe(ScriptPlatform::Windows)
        ->and($script->script_type)->toBe(ScriptType::Powershell)
        ->and($script->timeout_seconds)->toBe(600)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->script_content)->toBe(debloatScript());
});

it('removes apps for every user and deprovisions them for new users', function (): void {
    expect(debloatScript())
        ->toContain('Remove-AppxPackage -Package $_.PackageFullName -AllUsers')
        ->toContain('Remove-AppxProvisionedPackage -Online -PackageName $_.PackageName -AllUsers');
});

it('removes Copilot and the AI Hub by exact name', function (): void {
    expect(debloatList('apps'))
        ->toContain("'Microsoft.Copilot'")
        ->toContain("'Microsoft.Windows.AIHub'");
});

it('applies the per-user switches to signed-out profiles and the Default profile', function (): void {
    expect(debloatScript())
        ->toContain('reg.exe load $mount $file')
        ->toContain('reg.exe unload $mount')
        ->toContain('[gc]::Collect()')
        ->toContain('Users\Default\NTUSER.DAT')
        ->toContain('SilentInstalledAppsEnabled = 0')
        ->not->toContain('TaskbarDa')
        ->not->toContain('TaskbarMn');
});

it('names each protected app only on the protected list', function (string $app): void {
    expect(debloatList('protectedApps'))->toContain("'{$app}'")
        ->and(substr_count(debloatScript(), $app))->toBe(1);
})->with([
    'Microsoft.DesktopAppInstaller',
    'Microsoft.WindowsStore',
    'Microsoft.StorePurchaseApp',
    'Microsoft.SecHealthUI',
    'Microsoft.AAD.BrokerPlugin',
    'Microsoft.Xbox.TCUI',
    'Microsoft.XboxIdentityProvider',
    'Microsoft.GetHelp',
    'Microsoft.WindowsCalculator',
    'Microsoft.ScreenSketch',
]);

it('removes the apps Ben decided to bin', function (string $app): void {
    expect(debloatScript())->toContain("'{$app}'");
})->with([
    'Microsoft.MicrosoftStickyNotes',
    'MicrosoftCorporationII.QuickAssist',
    'Microsoft.Todos',
    'Microsoft.MicrosoftOfficeHub',
    'Microsoft.OutlookForWindows',
    'Microsoft.YourPhone',
    'MicrosoftWindows.CrossDevice',
    'DellInc.DellSupportAssistforPCs',
]);

it('removes desktop SupportAssist by MSI but never Dell Command Update', function (): void {
    expect(debloatScript())
        ->toContain("\$desktopApps = @('Dell SupportAssist*')")
        ->toContain('/x $($_.PSChildName) /qn /norestart')
        ->not->toContain('Command | Update*');
});

it('blocks OneDrive sync by policy rather than uninstalling it', function (): void {
    expect(debloatScript())
        ->toContain('DisableFileSyncNGSC = 1')
        ->not->toContain('OneDriveSetup.exe');
});

it('switches off Bing in Start search, the Copilot button and setup nags for every profile', function (string $setting): void {
    expect(debloatScript())->toContain($setting);
})->with([
    'BingSearchEnabled = 0',
    'DisableSearchBoxSuggestions = 1',
    'ShowCopilotButton = 0',
    'ScoobeSystemSettingEnabled = 0',
    'Windows.SystemToast.Suggested',
]);

it('never touches Defender, the Store or wildcard app names', function (string $forbidden): void {
    expect(debloatScript())->not->toContain($forbidden);
})->with([
    'Set-MpPreference',
    'RemoveWindowsStore',
    'Win32_Product |',
    '*Xbox*',
    '*Copilot*',
    'DiagTrack',
]);

it('keeps the script plain ASCII for Windows PowerShell 5.1', function (): void {
    expect(debloatScript())->not->toMatch('/[^\x00-\x7F]/');
});

it('reads the provisioned packages only after removing apps, since removing for all users usually deprovisions them', function (): void {
    $script = file_get_contents(resource_path('scripts/windows/debloat-windows.ps1'));

    expect(strpos($script, 'Get-AppxProvisionedPackage -Online'))->toBeGreaterThan(strpos($script, 'Remove-AppxPackage -Package'));
});
