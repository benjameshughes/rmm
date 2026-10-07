<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

pest()->use(RefreshDatabase::class);

function useScriptsFixture(array $files, array $system): string
{
    $path = sys_get_temp_dir().'/rmm-scripts-'.uniqid();

    collect($files)->each(function (string $content, string $file) use ($path): void {
        File::ensureDirectoryExists(dirname("{$path}/{$file}"));
        File::put("{$path}/{$file}", $content);
    });

    config(['scripts.path' => $path, 'scripts.system' => $system]);

    return $path;
}

function restartDefinition(array $overrides = []): array
{
    return [
        'name' => 'Restart',
        'description' => 'Force restart',
        'category' => 'power',
        'platform' => 'windows',
        'file' => 'windows/restart.ps1',
        'timeout_seconds' => 60,
        'requires_admin' => true,
        ...$overrides,
    ];
}

it('syncs every shipped system script from its file', function (): void {
    $count = app(SyncSystemScripts::class)();

    expect($count)->toBe(count(config('scripts.system')));
    expect(Script::query()->system()->count())->toBe($count);

    $logOff = Script::findSystem('log-off');
    expect($logOff->script_type)->toBe(ScriptType::Powershell);
    expect($logOff->platform)->toBe(ScriptPlatform::Windows);
    expect($logOff->script_content)->toBe(File::get(resource_path('scripts/windows/log-off.ps1')));
    expect(Script::findSystem('flush-dns')->script_type)->toBe(ScriptType::Cmd);
    expect(Script::findSystem('disk-usage')->script_type)->toBe(ScriptType::Bash);
});

it('is idempotent', function (): void {
    app(SyncSystemScripts::class)();
    $ids = Script::query()->system()->orderBy('id')->pluck('id');

    app(SyncSystemScripts::class)();

    expect(Script::query()->system()->orderBy('id')->pluck('id'))->toEqual($ids);
});

it('updates a system script when its file changes', function (): void {
    $path = useScriptsFixture(['windows/restart.ps1' => 'Restart-Computer'], ['restart' => restartDefinition()]);
    app(SyncSystemScripts::class)();

    File::put("{$path}/windows/restart.ps1", 'Restart-Computer -Force');
    app(SyncSystemScripts::class)();

    expect(Script::findSystem('restart')->script_content)->toBe('Restart-Computer -Force');
    expect(Script::query()->system()->count())->toBe(1);
});

it('removes system scripts that are no longer configured but keeps user scripts', function (): void {
    useScriptsFixture(['windows/restart.ps1' => 'Restart-Computer'], ['restart' => restartDefinition()]);
    Script::factory()->system()->create(['slug' => 'retired-script']);
    Script::factory()->system()->create(['slug' => null]);
    $userScript = Script::factory()->create();

    app(SyncSystemScripts::class)();

    expect(Script::query()->system()->pluck('slug')->all())->toBe(['restart']);
    expect($userScript->fresh())->not->toBeNull();
});

it('refuses to sync when a configured file is missing', function (): void {
    useScriptsFixture([], ['restart' => restartDefinition()]);

    app(SyncSystemScripts::class)();
})->throws(RuntimeException::class, "System script 'restart' points at a missing file");

it('refuses to sync a file extension with no configured script type', function (): void {
    useScriptsFixture(['windows/restart.bat' => 'shutdown /r'], ['restart' => restartDefinition(['file' => 'windows/restart.bat'])]);

    app(SyncSystemScripts::class)();
})->throws(RuntimeException::class, 'No script type is configured for windows/restart.bat');

it('can be run from artisan', function (): void {
    $this->artisan('scripts:sync')->assertSuccessful();

    expect(Script::findSystem('restart'))->not->toBeNull();
});

it('puts a script\'s shared includes in front of its own file, in order', function (): void {
    useScriptsFixture(
        ['windows/shared/a.ps1' => 'function A {}', 'windows/shared/b.ps1' => 'function B {}', 'windows/restart.ps1' => 'A; B'],
        ['restart' => restartDefinition(['includes' => ['windows/shared/a.ps1', 'windows/shared/b.ps1']])],
    );

    app(SyncSystemScripts::class)();

    expect(Script::findSystem('restart')->script_content)->toBe("function A {}\nfunction B {}\nA; B");
});

it('refuses an include that does not exist', function (): void {
    useScriptsFixture(['windows/restart.ps1' => 'A'], ['restart' => restartDefinition(['includes' => ['windows/shared/missing.ps1']])]);

    app(SyncSystemScripts::class)();
})->throws(RuntimeException::class, "System script 'restart' points at a missing file: windows/shared/missing.ps1");
