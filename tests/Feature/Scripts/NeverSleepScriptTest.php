<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs never-sleep as a Windows power script that never sleeps and blanks the screen after an hour', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('never-sleep');

    expect($script->category)->toBe(ScriptCategory::Power)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->script_content)->toContain('standby-timeout-ac 0')
        ->toContain('standby-timeout-dc 0')
        ->toContain('monitor-timeout-ac 60')
        ->toContain('monitor-timeout-dc 60');
});
