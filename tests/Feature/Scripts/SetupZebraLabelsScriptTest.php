<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs setup-zebra-labels as a script that sets 80 x 40 mm gap, tear-off labels and calibrates', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('setup-zebra-labels');

    expect($script->category)->toBe(ScriptCategory::Maintenance)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->script_content)->toContain('^MNY')
        ->toContain('^MMT')
        ->toContain('^PW640')
        ->toContain('^LL320')
        ->toContain('^JUS')
        ->toContain('~JC')
        ->toContain("'RAW'");
});
