<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

pest()->use(RefreshDatabase::class);

it('syncs the monitoring scripts with their category, timeout and admin flag', function (string $slug, ScriptCategory $category, int $timeout, bool $requiresAdmin): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem($slug);

    expect($script->category)->toBe($category);
    expect($script->platform)->toBe(ScriptPlatform::Windows);
    expect($script->script_type)->toBe(ScriptType::Powershell);
    expect($script->timeout_seconds)->toBe($timeout);
    expect($script->requires_admin)->toBe($requiresAdmin);
    expect($script->script_content)->toBe(File::get(resource_path("scripts/windows/{$slug}.ps1")));
})->with([
    'patch status' => ['patch-status', ScriptCategory::Updates, 900, true],
    'event log check' => ['event-log-check', ScriptCategory::Security, 120, true],
    'installed software' => ['installed-software', ScriptCategory::Info, 120, false],
]);

it('keeps the monitoring scripts plain ASCII so Windows PowerShell 5.1 reads them correctly', function (string $slug): void {
    expect(File::get(resource_path("scripts/windows/{$slug}.ps1")))->not->toMatch('/[^\x00-\x7F]/');
})->with(['patch-status', 'event-log-check', 'installed-software']);
