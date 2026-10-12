<?php

declare(strict_types=1);

use App\Actions\Printer\QueuePrinterAction;
use App\Actions\Script\SyncSystemScripts;
use App\Enums\PrinterAction;
use App\Enums\ScriptCategory;
use App\Enums\ScriptParameterType;
use App\Enums\ScriptPlatform;
use App\Models\Device;
use App\Models\Script;
use App\Models\User;
use App\Models\ZebraTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    config([
        'zebra.driver.url' => 'https://jot.wang/zebra/zd51177415.zip',
        'zebra.driver.sha256' => str_repeat('ab', 32),
    ]);
});

function zebraScript(string $file): string
{
    return File::get(resource_path("scripts/windows/{$file}.ps1"));
}

it('syncs both Zebra scripts as admin maintenance scripts with the shared include in front', function (string $slug, array $parameters, array $secrets): void {
    $script = Script::findSystem($slug);

    expect($script->category)->toBe(ScriptCategory::Maintenance)
        ->and($script->platform)->toBe(ScriptPlatform::Windows)
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->script_content)->toStartWith(zebraScript('shared/zebra'))
        ->and($script->script_content)->toEndWith(zebraScript($slug))
        ->and($script->parameters->map(fn ($parameter): array => [$parameter->name, $parameter->type, $parameter->isRequired])->all())->toBe($parameters)
        ->and($script->secretNames())->toBe($secrets);
})->with([
    'install' => ['install-zebra', [['TestPage', ScriptParameterType::Boolean, false]], ['ZebraDriverUrl', 'ZebraDriverSha256', 'ZebraDriverInf', 'ZebraDriverName', 'ZebraDriverPrefix', 'ZebraMatch', 'ZebraPnpWaitSeconds', 'ZebraTemplate', 'ZebraTemplateDriver']],
    'capture' => ['capture-zebra-template', [['PrinterName', ScriptParameterType::Text, true]], ['ZebraDriverPrefix', 'ZebraTemplateMaxChars']],
]);

it('keeps the Zebra scripts plain ASCII, and free of try/catch', function (string $file): void {
    $content = zebraScript($file);

    expect($content)->not->toMatch('/[^\x00-\x7F]/')
        ->and($content)->not->toMatch('/\btry\s*\{/i')
        ->and($content)->not->toMatch('/\bcatch\s*\{/i');
})->with(['install-zebra', 'capture-zebra-template', 'shared/zebra']);

it('locks the RMM zebra folder to SYSTEM and Administrators and runs printui quietly and synchronously', function (): void {
    expect(zebraScript('shared/zebra'))
        ->toContain("\$zebraDir = Join-Path \$rmmDir 'zebra'")
        ->toContain('$acl.SetAccessRuleProtection($true, $false)')
        ->toContain("\$trustedOwners = @('S-1-5-18', 'S-1-5-32-544')")
        ->toContain('ReparsePoint')
        ->toContain("'System32\\rundll32.exe'")
        ->toContain('-Wait -PassThru')
        ->toContain('Write-Output (ConvertTo-Json -InputObject @{ status = \'failed\'');
});

it('installs only the pinned driver: sha256 checked before pnputil, inside the locked folder', function (): void {
    $content = zebraScript('install-zebra');

    expect($content)
        ->toContain("\$driverSha256 -notmatch '^[0-9a-f]{64}\$'")
        ->toContain('(Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant() -eq $driverSha256')
        ->toContain("\$zip = Join-Path \$zebraDir 'driver.zip'")
        ->toContain('& $pnputil /add-driver $infPath /install')
        ->toContain('Add-PrinterDriver -Name $driverName')
        ->toContain('if ($null -eq (Get-ZebraDriver)) {')
        ->not->toContain('$env:TEMP')
        ->not->toContain('zebra.com')
        ->not->toContain('jot.wang');

    expect(strpos($content, 'Get-FileHash -LiteralPath $zip'))->toBeLessThan(strpos($content, '& $pnputil /add-driver'));
});

it('removes only other Zebra queues and never the one it just set up', function (): void {
    $content = zebraScript('install-zebra');

    expect($content)
        ->toContain('$queueName = $driverName')
        ->toContain('Get-LocalPrinters | Where-Object { (Test-ZebraQueue $_) -and $_.Name -ne $queueName } | ForEach-Object {')
        ->toContain('"$($printer.Name)" -match $matchPattern -or "$($printer.DriverName)" -match $matchPattern')
        ->toContain("\"\$(\$_.Type)\" -ne 'Connection'")
        ->toContain('-not (Test-ZDesignerDriver $_.Name) -and $driversInUse -notcontains $_.Name');

    expect(substr_count($content, 'Remove-Printer '))->toBe(1);
});

it('finds the Zebra on its USB port and keeps the queue under the driver name', function (): void {
    expect(zebraScript('install-zebra'))
        ->toContain("\$_.PortName -like 'USB*'")
        ->toContain('& $pnputil /scan-devices')
        ->toContain('Rename-Printer -NewName $queueName')
        ->toContain('Add-Printer -Name $queueName -DriverName $driverName -PortName $port');
});

it('restores the template from the secret only onto the same driver, with printer settings flags', function (): void {
    $content = zebraScript('install-zebra');

    expect($content)
        ->toContain('$templateB64 = "$env:RMM_ZebraTemplate".Trim()')
        ->toContain('elseif ($templateDriver -ne $driverName) {')
        ->toContain("Invoke-PrintUi @('/Sr', '/n', \$queueName, '/a', \$templateFile, '/q', 'g', 'd', 'r', 'p')")
        ->toContain('IO.Compression.CompressionMode]::Decompress')
        ->and(zebraScript('capture-zebra-template'))
        ->toContain("Invoke-PrintUi @('/Ss', '/n', \$printerName, '/a', \$settingsFile, '/q', 'g', 'd')")
        ->toContain('if (-not (Test-ZDesignerDriver $printer.DriverName)) {')
        ->toContain('$settingsB64.Length -gt $maxChars');
});

it('makes the queue the default printer for every profile and the Default profile', function (): void {
    expect(zebraScript('install-zebra'))
        ->toContain("Set-ItemProperty -LiteralPath \$windowsKey -Name 'LegacyDefaultPrinterMode' -Value 1 -Type DWord")
        ->toContain("Set-ItemProperty -LiteralPath \$windowsKey -Name 'Device' -Value \"\$queueName,\$portPart\"")
        ->toContain("'^S-1-5-21-[\\d-]+\$'")
        ->toContain('& reg.exe load "HKU\\$mountName" $hivePath')
        ->toContain('& reg.exe unload "HKU\\$mountName"')
        ->toContain('[gc]::Collect()')
        ->toContain('(Get-ItemProperty -LiteralPath $profileListPath).Default');
});

it('prints a test page only when asked and reports the final printer name', function (): void {
    expect(zebraScript('install-zebra'))
        ->toContain("\$isTestPageWanted = @('1', 'true') -contains \"\$env:RMM_TestPage\"")
        ->toContain('if ($isTestPageWanted) {')
        ->toContain('Write-Output "OK: Zebra installed as \'$queueName\'"')
        ->toContain('printer_name = $queueName')
        ->toContain('removed_queues = @($removedQueues)')
        ->toContain('template_applied = $isTemplateApplied')
        ->not->toContain('Virtual Printer');
});

it('hands install-zebra the driver pin and template only when the agent fetches it, never storing them', function (): void {
    $template = ZebraTemplate::factory()->create(['settings' => 'H4sIAAAAAAAAA0tMTklNSwcAx4j0xwQAAAA=']);
    $user = User::factory()->create();
    $device = Device::factory()->active()->windows()->withApiKey('ZEBRA-PC-KEY')->create(['agent_version' => '0.9.0']);

    $this->actingAs($user);
    $command = app(QueuePrinterAction::class)(PrinterAction::InstallZebra, $device, $user);

    $parameters = $this->withHeaders(['X-Agent-Key' => 'ZEBRA-PC-KEY'])->getJson('/api/commands/pending')->assertSuccessful()->json('command.parameters');

    expect($parameters)->toBe([
        'ZebraDriverUrl' => 'https://jot.wang/zebra/zd51177415.zip',
        'ZebraDriverSha256' => str_repeat('ab', 32),
        'ZebraDriverInf' => 'ZBRN/ZBRN.inf',
        'ZebraDriverName' => 'ZDesigner GK420d',
        'ZebraDriverPrefix' => 'ZDesigner',
        'ZebraMatch' => 'Zebra|ZDesigner|GK420',
        'ZebraPnpWaitSeconds' => '60',
        'ZebraTemplate' => $template->settings,
        'ZebraTemplateDriver' => 'ZDesigner GK420d',
    ]);

    $stored = collect(['device_commands', 'audit_logs'])->map(fn (string $table): string => DB::table($table)->get()->toJson())->implode("\n");

    expect($command->fresh()->parameters)->toBeNull()
        ->and($stored)->not->toContain($template->settings)
        ->and($stored)->not->toContain('jot.wang');
});

it('sends a blank template when none has been captured, and nothing Zebra to other scripts', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->active()->windows()->withApiKey('ZEBRA-PC-KEY')->create(['agent_version' => '0.9.0']);
    ZebraTemplate::factory()->create();

    app(QueuePrinterAction::class)(PrinterAction::PrintTestPage, $device, $user, printerName: 'ZDesigner GK420d');

    $parameters = $this->withHeaders(['X-Agent-Key' => 'ZEBRA-PC-KEY'])->getJson('/api/commands/pending')->json('command.parameters');

    expect($parameters)->toBe(['PrinterName' => 'ZDesigner GK420d']);

    ZebraTemplate::query()->delete();
    app(QueuePrinterAction::class)(PrinterAction::InstallZebra, $device, $user);

    $parameters = $this->withHeaders(['X-Agent-Key' => 'ZEBRA-PC-KEY'])->getJson('/api/commands/pending')->json('command.parameters');

    expect($parameters['ZebraTemplate'])->toBe('')
        ->and($parameters['ZebraTemplateDriver'])->toBe('');
});
