<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs same-day-orders-reminder as an admin user script that schedules the popup at 13:00 and 15:00', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('same-day-orders-reminder');

    expect($script->category)->toBe(ScriptCategory::User)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->script_content)->toContain('Check Same Day Orders')
        ->toContain("-At '13:00'")
        ->toContain("-At '15:00'")
        ->toContain("-GroupId 'S-1-5-32-545'")
        ->toContain('ATTENTION:');
});
