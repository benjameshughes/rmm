<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\DTOs\ScriptParameter;
use App\Enums\ScriptCategory;
use App\Enums\ScriptParameterType;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

pest()->use(RefreshDatabase::class);

beforeEach(fn () => app(SyncSystemScripts::class)());

it('syncs winget-install with its required PackageId parameter', function (): void {
    $script = Script::findSystem('winget-install');

    expect($script->category)->toBe(ScriptCategory::Updates);
    expect($script->requires_admin)->toBeTrue();
    expect($script->timeout_seconds)->toBe(1800);
    expect($script->parameters)->toHaveCount(1);

    $parameter = $script->parameters->first();
    expect($parameter)->toBeInstanceOf(ScriptParameter::class);
    expect($parameter->name)->toBe('PackageId');
    expect($parameter->type)->toBe(ScriptParameterType::Text);
    expect($parameter->isRequired)->toBeTrue();
});

it('syncs parameter changes and leaves parameterless system scripts without any', function (): void {
    config(['scripts.system.winget-install.parameters' => [
        ['name' => 'PackageId', 'label' => 'Winget ID', 'type' => 'text', 'required' => true],
        ['name' => 'Silent', 'label' => 'Silent', 'type' => 'boolean', 'required' => false],
    ]]);

    app(SyncSystemScripts::class)();

    expect(Script::findSystem('winget-install')->parameters->pluck('label')->all())->toBe(['Winget ID', 'Silent']);
    expect(Script::findSystem('restart')->getRawOriginal('parameters'))->toBeNull();
});

it('reads the package ID only from the environment and never from script text', function (): void {
    $content = File::get(resource_path('scripts/windows/winget-install.ps1'));

    expect($content)->toContain('$env:RMM_PackageId');
    expect($content)->toContain("'^[A-Za-z0-9][A-Za-z0-9._+-]*$'");
    expect($content)->toContain('install --id $packageId --exact --scope machine --silent --accept-package-agreements --accept-source-agreements --disable-interactivity');
});

it('keeps winget-install plain ASCII so Windows PowerShell 5.1 reads it correctly', function (): void {
    expect(File::get(resource_path('scripts/windows/winget-install.ps1')))->not->toMatch('/[^\x00-\x7F]/');
});
