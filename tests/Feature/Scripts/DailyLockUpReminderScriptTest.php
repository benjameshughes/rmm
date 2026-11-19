<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs daily-lock-up-reminder as an admin user script that schedules the popup at 16:00 every day', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('daily-lock-up-reminder');

    expect($script->category)->toBe(ScriptCategory::User)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->script_content)->toContain('Daily Lock Up')
        ->toContain("-Daily -At '16:00'")
        ->toContain("-GroupId 'S-1-5-32-545'")
        ->toContain('ATTENTION:');
});
