<?php

declare(strict_types=1);

use App\Actions\Script\RecordCommandProgress;
use App\Actions\Script\SyncSystemScripts;
use App\DTOs\CommandProgress;
use App\Enums\BackupScript;
use App\Enums\CommandStatus;
use App\Livewire\Commands\Detail;
use App\Livewire\Devices\Backups;
use App\Livewire\Devices\Header;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

function halfwayProgress(): CommandProgress
{
    return CommandProgress::fromArray([
        'schema' => 'rmm.progress/1',
        'percent' => 42.5,
        'done' => 7612,
        'total' => 18128,
        'unit' => 'files',
        'eta_seconds' => 312,
        'message' => 'Backing up',
        'current' => 'C:\\Users\\sophie\\Documents\\x.xlsx',
    ]);
}

function reportProgress(DeviceCommand $command, CommandProgress $progress, string $at): void
{
    app(RecordCommandProgress::class)($command, $progress, Carbon::parse($at));
}

it('renders an accessible progress bar with its label and the current item', function (): void {
    $html = Blade::render('<x-device.commands.progress :progress="$progress" />', ['progress' => halfwayProgress()]);

    expect($html)->toContain('data-flux-progress')
        ->toContain('wire:key="command-progress-42.5"')
        ->toContain('role="progressbar"')
        ->toContain('aria-valuenow="42.5"')
        ->toContain('aria-valuemin="0"')
        ->toContain('aria-valuemax="100"')
        ->toContain('aria-label="Backing up"')
        ->toContain('--flux-progress-percentage: 42.5%')
        ->toContain('42% · 7,612 / 18,128 files · ~5 min left')
        ->toContain('C:\\Users\\sophie\\Documents\\x.xlsx')
        ->toContain('dark:');
});

it('pulses without a value until the first report arrives', function (): void {
    $html = Blade::render('<x-device.commands.progress :progress="null" />');

    expect($html)->toContain('role="progressbar"')
        ->toContain('animate-pulse')
        ->not->toContain('aria-valuenow')
        ->not->toContain('data-command-progress-label');
});

it('can leave the script message out where a heading already says it', function (): void {
    $html = Blade::render('<x-device.commands.progress :progress="$progress" :with-message="false" />', ['progress' => halfwayProgress()]);

    expect($html)->not->toContain('>Backing up</span>')
        ->toContain('42% · 7,612 / 18,128 files');
});

it('shows the percent on a busy button only while the command runs with progress', function (CommandStatus $status, bool $hasProgress, string $expected, bool $showsPercent): void {
    $command = DeviceCommand::factory()->create(['status' => $status, 'progress' => $hasProgress ? halfwayProgress()->toArray() : null]);

    $html = Blade::render('<x-device.commands.busy :command="$command" />', ['command' => $command]);

    expect($html)->toContain($expected)
        ->and(str_contains($html, 'data-run-button-percent'))->toBe($showsPercent);
})->with([
    'running with progress' => [CommandStatus::Running, true, '42%', true],
    'running without progress' => [CommandStatus::Running, false, 'Running...', false],
    'sent with progress' => [CommandStatus::Sent, true, '42%', true],
    'pending with stale progress' => [CommandStatus::Pending, true, 'Queued...', false],
]);

it('shows the backup progress on the Backups tab and follows it live', function (): void {
    app(SyncSystemScripts::class)();
    $device = Device::factory()->active()->withBackupCredentials(setHoursAgo: 72)->create(['agent_version' => '0.9.2', 'last_seen' => now()]);
    $command = DeviceCommand::factory()->create([
        'device_id' => $device->id,
        'script_id' => Script::findSystem(BackupScript::BackUp->value)->id,
        'status' => CommandStatus::Running,
        'started_at' => now(),
    ]);

    $tab = Livewire::actingAs($this->user)->test(Backups::class, ['device' => $device])
        ->assertSeeHtml('data-backing-up')
        ->assertSee('Backing up now…')
        ->assertDontSeeHtml('data-command-progress-label');

    reportProgress($command, halfwayProgress(), '2026-10-09T08:00:00+00:00');

    $tab->dispatch("echo-private:devices.{$device->id},CommandProgressed", ['commandId' => $command->id, 'deviceId' => $device->id])
        ->assertSee('42% · 7,612 / 18,128 files · ~5 min left')
        ->assertSee('C:\\Users\\sophie\\Documents\\x.xlsx')
        ->assertSeeHtml('data-run-button-percent');

    $command->refresh()->markAsCompleted('OK: backed up', 0);

    $tab->dispatch("echo-private:devices.{$device->id},CommandUpdated", ['commandId' => $command->id, 'deviceId' => $device->id, 'status' => 'completed'])
        ->assertDontSeeHtml('data-backing-up')
        ->assertDontSee('7,612 / 18,128 files');
});

it('shows progress in the command detail while it runs, and refreshes on a progress event', function (): void {
    $command = DeviceCommand::factory()->create(['status' => CommandStatus::Running, 'started_at' => now()]);

    $detail = Livewire::actingAs($this->user)->test(Detail::class)
        ->dispatch('show-command', commandId: $command->id)
        ->assertDontSeeHtml('data-command-progress');

    reportProgress($command, halfwayProgress(), '2026-10-09T08:00:00+00:00');

    $detail->dispatch('echo-private:devices,CommandProgressed', ['commandId' => $command->id, 'deviceId' => $command->device_id])
        ->assertSeeHtml('data-command-progress')
        ->assertSee('42% · 7,612 / 18,128 files');

    $command->refresh()->markAsCompleted('all done', 0);

    $detail->dispatch('echo-private:devices,CommandUpdated', ['commandId' => $command->id, 'deviceId' => $command->device_id, 'status' => 'completed'])
        ->assertDontSeeHtml('data-command-progress')
        ->assertSee('all done');
});

it('skips rendering the command detail for progress on another command', function (): void {
    $shown = DeviceCommand::factory()->create(['status' => CommandStatus::Running]);
    $other = DeviceCommand::factory()->create(['status' => CommandStatus::Running]);

    $detail = Livewire::actingAs($this->user)->test(Detail::class)
        ->dispatch('show-command', commandId: $shown->id);

    reportProgress($shown, halfwayProgress(), '2026-10-09T08:00:00+00:00');

    $detail->dispatch('echo-private:devices,CommandProgressed', ['commandId' => $other->id, 'deviceId' => $other->device_id])
        ->assertDontSeeHtml('data-command-progress');
});

it('updates the percent on the device header busy button from its own channel', function (): void {
    $device = Device::factory()->active()->windows()->create(['agent_version' => '0.9.2']);
    $command = DeviceCommand::factory()->create(['device_id' => $device->id, 'status' => CommandStatus::Running, 'script_id' => null, 'script_type' => 'powershell', 'script_content' => 'Get-ChildItem']);

    $header = Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->assertSeeHtml('data-run-button-busy')
        ->assertDontSeeHtml('data-run-button-percent');

    reportProgress($command, halfwayProgress(), '2026-10-09T08:00:00+00:00');

    $header->dispatch("echo-private:devices.{$device->id},CommandProgressed", ['commandId' => $command->id, 'deviceId' => $device->id])
        ->assertSeeHtml('data-run-button-percent');
});
