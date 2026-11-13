<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs add-label-form as a Windows script that adds an 80 x 40 mm paper size', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('add-label-form');

    expect($script->category)->toBe(ScriptCategory::Maintenance)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->script_content)->toContain('$widthMm = 80')
        ->toContain('$heightMm = 40')
        ->toContain('AddForm')
        ->toContain('SetForm');
});
