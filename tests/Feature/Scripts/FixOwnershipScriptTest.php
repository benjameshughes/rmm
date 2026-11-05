<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs fix-ownership as an admin maintenance script for Windows with a path and username', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('fix-ownership');

    expect($script->category)->toBe(ScriptCategory::Maintenance)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->platform->value)->toBe('windows')
        ->and($script->parameters->pluck('name')->all())->toBe(['Path', 'Username']);
});

it('sets the owner and an inherited full-control grant by SID', function (): void {
    $script = file_get_contents(resource_path('scripts/windows/fix-ownership.ps1'));

    expect($script)
        ->toContain('/setowner "*$sid"')
        ->toContain('(OI)(CI)F')
        ->toContain('Get-LocalUser');
});
