<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs enforce-windows-security as an admin security script that turns UAC, Defender and the firewall on', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('enforce-windows-security');

    expect($script->category)->toBe(ScriptCategory::Security)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->script_content)->toContain('EnableLUA')
        ->toContain('Remove-ItemProperty')
        ->toContain('-DisableRealtimeMonitoring $false')
        ->toContain('Set-NetFirewallProfile -Profile Domain, Private, Public -Enabled True')
        ->toContain('ATTENTION:');
});
