<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\PrinterAction;
use App\Enums\ScriptCategory;
use App\Enums\ScriptParameterType;
use App\Enums\ScriptPlatform;
use App\Models\Script;
use Illuminate\Support\Facades\File;

beforeEach(fn () => app(SyncSystemScripts::class)());

function printerScript(PrinterAction $action): string
{
    return File::get(resource_path("scripts/windows/{$action->value}.ps1"));
}

it('syncs every print queue script as an admin maintenance script for Windows', function (PrinterAction $action, array $parameters): void {
    $script = Script::findSystem($action->value);

    expect($script->category)->toBe(ScriptCategory::Maintenance)
        ->and($script->platform)->toBe(ScriptPlatform::Windows)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->parameters->map(fn ($parameter): array => [$parameter->name, $parameter->type, $parameter->isRequired])->all())->toBe($parameters);
})->with([
    'clear queue' => [PrinterAction::ClearQueue, [['PrinterName', ScriptParameterType::Text, true]]],
    'cancel job' => [PrinterAction::CancelJob, [['PrinterName', ScriptParameterType::Text, true], ['JobId', ScriptParameterType::Number, true]]],
    'restart spooler' => [PrinterAction::RestartSpooler, []],
    'test page' => [PrinterAction::PrintTestPage, [['PrinterName', ScriptParameterType::Text, true]]],
]);

it('keeps the print queue scripts plain ASCII and free of try/catch for Windows PowerShell 5.1', function (PrinterAction $action): void {
    $content = printerScript($action);

    expect($content)->not->toMatch('/[^\x00-\x7F]/')
        ->and($content)->not->toMatch('/\btry\s*\{/i')
        ->and($content)->toMatch('/Write-Output [\'"]ATTENTION: /')
        ->and($content)->toContain('exit 1')
        ->and($content)->toContain('exit 0');
})->with(PrinterAction::cases());

it('reads the printer name only from the environment and matches it exactly, never as a wildcard or WMI filter', function (PrinterAction $action): void {
    $content = printerScript($action);

    expect($content)->toContain('$printerName = "$env:RMM_PrinterName"')
        ->and($content)->toContain('$_.Name -eq $printerName')
        ->and($content)->toContain("-match '[\\x00-\\x1f]'")
        ->and($content)->not->toContain('-Name $printerName')
        ->and($content)->not->toContain('-PrinterName $printerName')
        ->and($content)->not->toContain('-Filter');
})->with([PrinterAction::ClearQueue, PrinterAction::CancelJob, PrinterAction::PrintTestPage]);

it('removes jobs through the printer object and checks they are gone', function (): void {
    expect(printerScript(PrinterAction::ClearQueue))->toContain('Get-PrintJob -PrinterObject $printer')->toContain('Remove-PrintJob')
        ->and(printerScript(PrinterAction::CancelJob))->toContain('$jobId -notmatch \'^\d{1,10}$\'')->toContain('-ID ([uint32] $jobId)');
});

it('restarts the spooler by force and waits for it to run', function (): void {
    expect(printerScript(PrinterAction::RestartSpooler))
        ->toContain("Stop-Service -Name 'Spooler' -Force")
        ->toContain("Stop-Process -Name 'spoolsv' -Force")
        ->toContain("Start-Service -Name 'Spooler'")
        ->toContain("-eq 'Running'");
});

it('prints the Windows test page through Win32_Printer', function (): void {
    expect(printerScript(PrinterAction::PrintTestPage))->toContain('Invoke-CimMethod -InputObject $printer -MethodName PrintTestPage');
});

it('passes printer names with spaces, hashes and brackets through untouched', function (): void {
    $values = app(ValidateScriptParameterValues::class)(Script::findSystem('cancel-print-job'), ['PrinterName' => 'Zebra GK420d - ZPL #2 (Copy 1)', 'JobId' => 7]);

    expect($values)->toBe(['PrinterName' => 'Zebra GK420d - ZPL #2 (Copy 1)', 'JobId' => '7']);
});
