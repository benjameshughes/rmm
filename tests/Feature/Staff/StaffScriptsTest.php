<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Models\Script;

beforeEach(fn () => app(SyncSystemScripts::class)());

it('syncs sync-local-user as an internal script that takes the password only as a secret', function (): void {
    $script = Script::findSystem('sync-local-user');

    expect($script->is_internal)->toBeTrue()
        ->and($script->secretNames())->toBe(['StaffPassword'])
        ->and($script->parameters->pluck('name')->all())->toBe(['StaffAccountId', 'Username', 'FullName', 'IsAdmin', 'IsEnabled'])
        ->and($script->script_content)->toContain('S-1-5-32-544')
        ->toContain('-PasswordNeverExpires')
        ->toContain('-UserMayNotChangePassword')
        ->toContain('$env:RMM_StaffPassword')
        ->toContain('Write-Output "OK:')
        ->not->toContain("'Administrators'");
});

it('syncs remove-local-user as an internal script that spares built-in accounts and the signed-in user', function (): void {
    $script = Script::findSystem('remove-local-user');

    expect($script->is_internal)->toBeTrue()
        ->and($script->script_content)->toContain("'500', '501', '503', '504'")
        ->toContain('Win32_ComputerSystem');
});

it('syncs set-screen-lock as a machine-wide idle lock taking minutes', function (): void {
    $script = Script::findSystem('set-screen-lock');

    expect($script->is_internal)->toBeFalse()
        ->and($script->parameters->pluck('name')->all())->toBe(['IdleMinutes'])
        ->and($script->script_content)->toContain('InactivityTimeoutSecs');
});
