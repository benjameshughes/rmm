<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs disk-cleanup as an admin maintenance script for Windows', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('disk-cleanup');

    expect($script->category)->toBe(ScriptCategory::Maintenance)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->platform->value)->toBe('windows');
});

it('only clears week-old temp files, dumps and caches, never anything a person made', function (): void {
    $script = file_get_contents(resource_path('scripts/windows/disk-cleanup.ps1'));

    expect($script)
        ->toContain('(Get-Date).AddDays(-7)')
        ->toContain('/StartComponentCleanup')
        ->toContain('Freed $freedGb GB')
        ->not->toContain('Clear-RecycleBin')
        ->not->toContain('$Recycle.Bin')
        ->not->toContain('\\Downloads')
        ->not->toContain('\\Documents')
        ->not->toContain('/ResetBase')
        ->not->toMatch('/[^\x00-\x7F]/');
});
