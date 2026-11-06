<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\ScriptCategory;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('syncs add-cups-printer with the CUPS server from config and the IPP class driver', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('add-cups-printer');

    expect($script->category)->toBe(ScriptCategory::Maintenance)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->is_internal)->toBeFalse()
        ->and($script->parameters->pluck('default', 'name')->all())->toBe([
            'Server' => config('printers.cups.server'),
            'Port' => (string) config('printers.cups.port'),
            'Printer' => null,
            'DisplayName' => null,
            'Driver' => 'Microsoft IPP Class Driver',
        ])
        ->and($script->script_content)->toContain('function Get-CupsPrinters');
});

it('syncs remove-cups-printer with the shared CUPS checks', function (): void {
    app(SyncSystemScripts::class)();

    $script = Script::findSystem('remove-cups-printer');

    expect($script->parameters->pluck('name')->all())->toBe(['Server', 'Port', 'Printer', 'DisplayName'])
        ->and($script->script_content)->toContain('function Get-CupsPrinters');
});

it('validates the parameters strictly and builds the IPP URL', function (): void {
    $script = file_get_contents(resource_path('scripts/windows/shared/cups.ps1'));

    expect($script)
        ->toContain('$env:RMM_Printer')
        ->toContain("'^[A-Za-z0-9][A-Za-z0-9_.-]{0,126}$'")
        ->toContain('$cupsUrl = "http://${cupsServer}:$cupsPort/printers/$cupsQueue"');
});

it('adds a local IPP queue machine-wide and verifies it with Get-Printer', function (): void {
    $script = file_get_contents(resource_path('scripts/windows/add-cups-printer.ps1'));

    expect($script)
        ->toContain('Add-Printer -IppURL $cupsUrl')
        ->toContain('-PortName $cupsUrl -DriverName $classDriver')
        ->toContain('Get-Printer')
        ->toContain('OK: ');
});

it('removes the printers and their unused port', function (): void {
    $script = file_get_contents(resource_path('scripts/windows/remove-cups-printer.ps1'));

    expect($script)
        ->toContain('Remove-Printer -Name $printer.Name')
        ->toContain('Remove-PrinterPort -Name $port');
});

it('keeps the CUPS scripts plain ASCII without Stop-Run', function (string $file): void {
    $script = file_get_contents(resource_path("scripts/windows/{$file}"));

    expect(mb_check_encoding($script, 'ASCII'))->toBeTrue()
        ->and($script)->not->toContain('Stop-Run');
})->with(['shared/cups.ps1', 'add-cups-printer.ps1', 'remove-cups-printer.ps1']);
