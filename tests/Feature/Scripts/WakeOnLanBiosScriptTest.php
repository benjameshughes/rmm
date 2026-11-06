<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs wake-on-lan-bios as a Windows power script that sets the BIOS through Dell, HP and Lenovo WMI', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('wake-on-lan-bios');

    expect($script->category)->toBe(ScriptCategory::Power)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->script_content)->toContain('root\dcim\sysman\biosattributes')
        ->toContain('root\HP\InstrumentedBIOS')
        ->toContain('Lenovo_SetBiosSetting')
        ->toContain('SaveBiosSettings')
        ->toContain('Offers');
});
