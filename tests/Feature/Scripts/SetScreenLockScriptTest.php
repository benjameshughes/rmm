<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs set-screen-lock as a public script with idle minutes and sign-in on wake', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('set-screen-lock');

    expect($script->is_internal)->toBeFalse()
        ->and($script->parameters->pluck('default', 'name')->all())->toBe(['IdleMinutes' => '10', 'RequirePasswordOnWake' => 'true']);
});

it('turns the lock off at 0 minutes and sets sign-in on wake with powercfg', function (): void {
    $script = file_get_contents(resource_path('scripts/windows/set-screen-lock.ps1'));

    expect($script)
        ->toContain("Remove-ItemProperty -Path \$key -Name 'InactivityTimeoutSecs'")
        ->toContain('OK: $summary')
        ->toContain("'Screen lock turned off'")
        ->toContain("'/SETACVALUEINDEX', 'SCHEME_CURRENT', 'SUB_NONE', 'CONSOLELOCK'")
        ->toContain("'/SETDCVALUEINDEX', 'SCHEME_CURRENT', 'SUB_NONE', 'CONSOLELOCK'")
        ->toContain("'/SETACTIVE', 'SCHEME_CURRENT'");
});
