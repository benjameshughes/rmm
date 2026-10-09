<?php

declare(strict_types=1);

use App\Actions\DeletePath\QueueDeletePath;
use App\Actions\DeletePath\QueueQuarantinePurge;
use App\Actions\DeletePath\QueueQuarantineRestore;
use App\Actions\Script\SyncSystemScripts;
use App\Enums\DeleteMode;
use App\Exceptions\QuarantineCannotBeChanged;
use App\Livewire\Devices\Storage;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceQuarantine;
use App\Models\Script;
use App\Models\User;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['hostname' => 'DESKTOP-TF6VC1D', 'agent_version' => '0.9.0']);
});

function purgesQueued(): array
{
    return DeviceCommand::query()->where('script_id', Script::findSystem('purge-quarantine')->id)->get()
        ->map(fn (DeviceCommand $command): array => [$command->device_id, $command->parameters])
        ->all();
}

it('queues the daily purge only on Windows PCs holding quarantined items past their date', function (): void {
    DeviceQuarantine::factory()->due()->create(['device_id' => $this->device->id]);
    DeviceQuarantine::factory()->create(['device_id' => Device::factory()->active()->windows()->create(['agent_version' => '0.9.0'])->id]);
    DeviceQuarantine::factory()->due()->purged()->create(['device_id' => Device::factory()->active()->windows()->create(['agent_version' => '0.9.0'])->id]);
    DeviceQuarantine::factory()->due()->restored()->create(['device_id' => Device::factory()->active()->windows()->create(['agent_version' => '0.9.0'])->id]);
    DeviceQuarantine::factory()->due()->create(['device_id' => Device::factory()->active()->windows()->monitorOnly()->create()->id]);
    DeviceQuarantine::factory()->due()->create(['device_id' => Device::factory()->active()->linux()->create()->id]);

    $this->artisan('quarantine:purge')->assertSuccessful();
    $this->artisan('quarantine:purge')->assertSuccessful();

    expect(purgesQueued())->toBe([[$this->device->id, ['Days' => '7']]])
        ->and(DeviceCommand::query()->sole()->queued_by)->toBe(User::automation()->id);
});

it('is scheduled daily', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains($event->command ?? '', 'quarantine:purge'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * *');
});

it('lists what is held in quarantine on the Storage tab, nothing once purged or restored', function (): void {
    $this->freezeSecond();
    DeviceQuarantine::factory()->create(['device_id' => $this->device->id, 'path' => 'C:\\Veeam Backup Cache']);
    DeviceQuarantine::factory()->purged()->create(['device_id' => $this->device->id, 'path' => 'C:\\Gone Already']);
    DeviceQuarantine::factory()->restored()->create(['device_id' => $this->device->id, 'path' => 'C:\\Put Back']);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertSeeHtml('data-quarantine')
        ->assertSee('C:\\Veeam Backup Cache')
        ->assertSee('103.7 GB')
        ->assertSee('purged in 6 days')
        ->assertDontSee('C:\\Gone Already')
        ->assertDontSee('C:\\Put Back');
});

it('stays calm with nothing in quarantine', function (): void {
    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertDontSeeHtml('data-quarantine');
});

it('purges one item now and shows it busy until done', function (): void {
    $quarantine = DeviceQuarantine::factory()->create(['device_id' => $this->device->id]);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertSeeHtml('data-purge-quarantine')
        ->assertSeeHtml('$wire.purgeQuarantine('.$quarantine->id.')')
        ->call('purgeQuarantine', $quarantine->id)
        ->assertDispatched('command-queued')
        ->assertSeeHtml('data-run-button-busy')
        ->assertDontSeeHtml('data-purge-quarantine');

    expect(purgesQueued())->toBe([[$this->device->id, ['Days' => '0', 'Folder' => $quarantine->folder]]]);
});

it('restores one item by its folder and original path', function (): void {
    $quarantine = DeviceQuarantine::factory()->create(['device_id' => $this->device->id]);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->call('restoreQuarantine', $quarantine->id)
        ->call('restoreQuarantine', $quarantine->id)
        ->assertSeeHtml('data-run-button-busy');

    expect(DeviceCommand::query()->sole())
        ->script_id->toBe(Script::findSystem('restore-quarantine')->id)
        ->parameters->toBe(['Folder' => $quarantine->folder, 'Path' => 'C:\\Veeam Backup Cache']);
});

it('refuses to purge or restore what is already gone', function (): void {
    $purged = DeviceQuarantine::factory()->purged()->create(['device_id' => $this->device->id]);

    expect(fn () => app(QueueQuarantinePurge::class)($this->device, $this->user, $purged))->toThrow(QuarantineCannotBeChanged::class)
        ->and(fn () => app(QueueQuarantineRestore::class)($purged, $this->user))->toThrow(QuarantineCannotBeChanged::class)
        ->and(DeviceCommand::query()->count())->toBe(0);
});

it('hides quarantine actions on a PC the user cannot delete on', function (): void {
    DeviceQuarantine::factory()->create(['device_id' => $this->device->id]);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'deletePaths' ? false : null);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertSeeHtml('data-quarantine')
        ->assertDontSeeHtml('data-quarantine-actions')
        ->assertDontSeeHtml('data-delete-file')
        ->call('purgeQuarantine', DeviceQuarantine::query()->sole()->id)
        ->assertForbidden();
});

it('renders the Storage tab in the same number of queries however many items are quarantined or in flight', function (): void {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device]);

        return count(DB::getQueryLog());
    };

    DeviceQuarantine::factory()->create(['device_id' => $this->device->id]);
    app(QueueDeletePath::class)($this->device, $this->user, 'C:\\One', DeleteMode::Delete);
    $few = $queries();

    DeviceQuarantine::factory()->count(5)->create(['device_id' => $this->device->id]);
    collect(['C:\\Two', 'C:\\Three', 'C:\\Four'])->each(fn (string $path) => app(QueueDeletePath::class)($this->device, $this->user, $path, DeleteMode::Delete));
    app(QueueQuarantinePurge::class)($this->device, $this->user, DeviceQuarantine::query()->first());
    $many = $queries();

    expect($many)->toBe($few);
});
