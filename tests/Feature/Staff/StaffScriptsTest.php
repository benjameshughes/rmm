<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Models\Script;

beforeEach(fn () => app(SyncSystemScripts::class)());

it('syncs sync-local-user as an internal script that sets only the temp password, and only on reset', function (): void {
    $script = Script::findSystem('sync-local-user');

    expect($script->is_internal)->toBeTrue()
        ->and($script->secretNames())->toBe(['StaffTempPassword'])
        ->and($script->parameters->pluck('name')->all())->toBe(['StaffAccountId', 'Username', 'FullName', 'IsAdmin', 'IsEnabled', 'ResetPassword'])
        ->and($script->script_content)->toContain('S-1-5-32-544')
        ->toContain('$env:RMM_StaffTempPassword')
        ->toContain('if ($result.created -or $resetPassword) {')
        ->toContain('/logonpasswordchg:yes')
        ->toContain('Write-Output "OK:')
        ->not->toContain('-UserMayNotChangePassword')
        ->not->toContain('-PasswordNeverExpires $true')
        ->not->toContain('RMM_StaffPassword')
        ->not->toContain("'Administrators'");
});

it('only sets the password inside the create-or-reset block', function (): void {
    $content = Script::findSystem('sync-local-user')->script_content;
    $outsideReset = str($content)->before('if ($result.created -or $resetPassword) {')->toString().str($content)->after('$result.password_reset = $true')->toString();

    expect($outsideReset)->not->toMatch('/Set-LocalUser[^\r\n]*-Password\s/');
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
