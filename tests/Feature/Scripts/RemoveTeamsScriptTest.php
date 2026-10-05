<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs remove-teams to remove Teams for every user and block it coming back', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('remove-teams');

    expect($script->category)->toBe(ScriptCategory::Maintenance)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->script_content)
        ->toContain('Remove-AppxPackage -Package $_.PackageFullName -AllUsers')
        ->toContain('Remove-AppxProvisionedPackage -Online')
        ->toContain('TeamsMeetingAdd-in')
        ->toContain("-Name 'PreventTeamsInstall' -Value 1")
        ->toContain("-Name 'ChatIcon' -Value 3")
        ->not->toContain('Win32_Product |');
});
