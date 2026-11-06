<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs windows-feature-upgrade with an optional target version that pins the PC before searching', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('windows-feature-upgrade');

    expect($script->parameters->pluck('name')->all())->toBe(['TargetVersion'])
        ->and($script->script_content)->toContain('TargetReleaseVersionInfo')
        ->toContain("if (\$build -ge 22000) { 'Windows 11' } else { 'Windows 10' }")
        ->and(strpos($script->script_content, 'TargetReleaseVersionInfo'))->toBeLessThan(strpos($script->script_content, '$searcher.Search('));
});

it('never calls Stop-Run in scripts that do not include the shared helpers that define it', function (string $slug): void {
    expect(file_get_contents(resource_path("scripts/windows/{$slug}.ps1")))->not->toContain('Stop-Run');
})->with(['windows-feature-upgrade', 'wake-on-lan-bios']);
