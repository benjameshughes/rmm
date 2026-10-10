<?php

declare(strict_types=1);

use App\Actions\DeletePath\QueueDeletePath;
use App\Actions\DiskUsage\QueueDiskScan;
use App\Actions\Printer\QueuePrinterAction;
use App\Actions\Schedule\RunScheduledTask;
use App\Actions\Script\SyncSystemScripts;
use App\Actions\Software\QueuePackageAction;
use App\Enums\DeleteMode;
use App\Enums\PackageAction;
use App\Enums\PrinterAction;
use App\Exceptions\ScriptCannotBeRunDirectly;
use App\Livewire\Devices\Header;
use App\Livewire\Devices\Index;
use App\Livewire\ScheduledTasks\Index as ScheduledTasksIndex;
use App\Livewire\Scripts\Index as ScriptsIndex;
use App\Livewire\Scripts\Show;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['agent_version' => '0.9.0']);
});

/**
 * @return array<int, string>
 */
function internalScriptSlugs(): array
{
    return collect(config('scripts.system'))->filter(fn (array $definition): bool => $definition['internal'] ?? false)->keys()->all();
}

function assertRefusedAsInternal(Closure $run): void
{
    expect($run)->toThrow(function (ScriptCannotBeRunDirectly $exception): void {
        expect($exception->getStatusCode())->toBe(403);
    });
}

it('marks the feature-owned system scripts as internal when it syncs them', function (): void {
    expect(internalScriptSlugs())->toEqualCanonicalizing([
        'update-agent', 'backup-snapshots', 'backup-restore', 'disk-usage', 'remove-path', 'purge-quarantine', 'restore-quarantine',
        'clear-print-queue', 'cancel-print-job', 'restart-print-spooler', 'print-test-page', 'winget-install', 'winget-upgrade', 'winget-uninstall',
    ]);

    expect(Script::query()->where('is_internal', true)->pluck('slug')->all())->toEqualCanonicalizing(internalScriptSlugs())
        ->and(Script::findSystem('restart')->is_internal)->toBeFalse()
        ->and(Script::findSystem('backup-files')->is_internal)->toBeFalse()
        ->and(Script::findSystem('winget-inventory')->is_internal)->toBeFalse();
});

it('clears the internal flag when a script stops being internal', function (): void {
    config(['scripts.system.remove-path.internal' => false]);

    app(SyncSystemScripts::class)();

    expect(Script::findSystem('remove-path')->is_internal)->toBeFalse();
});

it('leaves user scripts runnable', function (): void {
    $script = Script::factory()->create();

    expect($script->refresh()->is_internal)->toBeFalse()
        ->and(Script::query()->runnableDirectly()->whereKey($script->id)->exists())->toBeTrue();
});

it('leaves internal scripts out of the device Run Script picker', function (): void {
    Livewire::actingAs($this->user)
        ->test(Header::class, ['device' => $this->device])
        ->set('showScriptModal', true)
        ->assertViewHas('scripts', fn ($scripts): bool => $scripts->pluck('slug')->intersect(internalScriptSlugs())->isEmpty() && $scripts->contains('slug', 'restart'));
});

it('leaves internal scripts out of the bulk Run Script picker', function (): void {
    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->assertViewHas('scripts', fn ($scripts): bool => $scripts->pluck('slug')->intersect(internalScriptSlugs())->isEmpty() && $scripts->contains('slug', 'restart'));
});

it('leaves internal scripts out of the schedule script picker', function (): void {
    Livewire::actingAs($this->user)
        ->test(ScheduledTasksIndex::class)
        ->assertViewHas('scripts', fn ($scripts): bool => $scripts->pluck('slug')->intersect(internalScriptSlugs())->isEmpty() && $scripts->contains('slug', 'winget-inventory'));
});

it('lists internal scripts on the scripts page with an Internal badge', function (): void {
    Livewire::actingAs($this->user)
        ->test(ScriptsIndex::class)
        ->set('search', 'Delete Path')
        ->assertSee('Delete Path')
        ->assertSeeHtml('data-internal-badge');
});

it('shows an internal script without an Execute button', function (): void {
    Livewire::actingAs($this->user)
        ->test(Show::class, ['script' => Script::findSystem('remove-path')])
        ->assertSeeHtml('data-internal-badge')
        ->assertSee('Queued by its own feature')
        ->assertDontSeeHtml("\$set('showExecuteModal', true)");
});

it('shows a runnable script with its Execute button', function (): void {
    Livewire::actingAs($this->user)
        ->test(Show::class, ['script' => Script::findSystem('restart')])
        ->assertDontSeeHtml('data-internal-badge')
        ->assertSeeHtml("\$set('showExecuteModal', true)");
});

it('refuses an internal script from the device Run Script action', function (string $slug): void {
    assertRefusedAsInternal(fn () => Livewire::actingAs($this->user)
        ->test(Header::class, ['device' => $this->device])
        ->set('selectedScriptId', Script::findSystem($slug)->id)
        ->call('runScript'));

    expect(DeviceCommand::query()->count())->toBe(0);
})->with(['remove-path', 'purge-quarantine', 'restore-quarantine', 'backup-restore', 'update-agent']);

it('refuses an internal script from the bulk Run Script action', function (): void {
    assertRefusedAsInternal(fn () => Livewire::actingAs($this->user)
        ->test(Index::class)
        ->set('selectedDevices', [(string) $this->device->id])
        ->set('bulkScriptId', Script::findSystem('remove-path')->id)
        ->call('bulkRunScript'));

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('refuses to execute an internal script from its script page', function (): void {
    assertRefusedAsInternal(fn () => Livewire::actingAs($this->user)
        ->test(Show::class, ['script' => Script::findSystem('remove-path')])
        ->set('selectedDeviceIds', [$this->device->id])
        ->call('executeOnDevices'));

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('refuses to schedule an internal script', function (string $method): void {
    $task = ScheduledTask::factory()->create(['script_id' => Script::findSystem('restart')->id]);

    $component = Livewire::actingAs($this->user)->test(ScheduledTasksIndex::class);

    if ($method === 'update') {
        $component->call('edit', $task->id);
    }

    assertRefusedAsInternal(fn () => $component
        ->set('name', 'Nightly delete')
        ->set('script_id', Script::findSystem('remove-path')->id)
        ->call($method));

    expect(ScheduledTask::query()->where('script_id', Script::findSystem('remove-path')->id)->exists())->toBeFalse();
})->with(['create', 'update']);

it('skips a schedule that points at an internal script', function (): void {
    $task = ScheduledTask::factory()->create(['script_id' => Script::findSystem('remove-path')->id, 'created_by' => $this->user->id]);

    expect(app(RunScheduledTask::class)($task))->toBe(0)
        ->and(DeviceCommand::query()->count())->toBe(0);
});

it('still runs general-purpose system scripts from the device Run Script action', function (): void {
    Livewire::actingAs($this->user)
        ->test(Header::class, ['device' => $this->device])
        ->set('selectedScriptId', Script::findSystem('disk-cleanup')->id)
        ->call('runScript')
        ->assertHasNoErrors()
        ->assertDispatched('command-queued');

    expect(DeviceCommand::query()->sole()->script_id)->toBe(Script::findSystem('disk-cleanup')->id);
});

it('still lets each feature queue its internal script', function (Closure $queue, string $slug): void {
    $queue($this->device, $this->user);

    expect(DeviceCommand::query()->sole()->script_id)->toBe(Script::findSystem($slug)->id);
})->with([
    'delete path' => [fn (Device $device, User $user) => app(QueueDeletePath::class)($device, $user, 'C:\\Veeam Backup Cache', DeleteMode::Quarantine), 'remove-path'],
    'disk scan' => [fn (Device $device, User $user) => app(QueueDiskScan::class)($device, $user, 'C:\\'), 'disk-usage'],
    'printer test page' => [fn (Device $device, User $user) => app(QueuePrinterAction::class)(PrinterAction::PrintTestPage, $device, $user, 'Zebra ZD420'), 'print-test-page'],
    'software uninstall' => [fn (Device $device, User $user) => app(QueuePackageAction::class)(PackageAction::Uninstall, $device, 'Mozilla.Firefox', $user), 'winget-uninstall'],
]);

it('still updates the agent from the device header and the bulk action', function (): void {
    Livewire::actingAs($this->user)
        ->test(Header::class, ['device' => $this->device])
        ->call('updateAgent')
        ->assertDispatched('command-queued');

    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->set('selectedDevices', [(string) $this->device->id])
        ->call('bulkUpdateAgent');

    expect(DeviceCommand::query()->where('script_id', Script::findSystem('update-agent')->id)->count())->toBe(2);
});
