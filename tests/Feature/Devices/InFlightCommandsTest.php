<?php

declare(strict_types=1);

use App\Enums\CommandStatus;
use App\Enums\DevicePowerState;
use App\Livewire\Devices\Overview;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['hostname' => 'BUSY-PC', 'last_seen' => now()]);
});

function commandOn(Device $device, CommandStatus $status, string $scriptName, array $attributes = []): DeviceCommand
{
    return DeviceCommand::factory()->create([
        'device_id' => $device->id,
        'script_id' => Script::factory()->create(['name' => $scriptName])->id,
        'status' => $status,
        'queued_by' => test()->user->id,
        ...$attributes,
    ]);
}

it('stays hidden when nothing is in flight, however recent the history', function (): void {
    commandOn($this->device, CommandStatus::Completed, 'Clear Temp', ['queued_at' => now()->subMinute(), 'completed_at' => now()]);
    commandOn($this->device, CommandStatus::Cancelled, 'Never Ran');

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertDontSeeHtml('data-in-flight');
});

it('shows what is running and when it started, opening its detail on click', function (CommandStatus $status, string $timestamp): void {
    $command = commandOn($this->device, $status, 'Defrag C', [$timestamp => now()->subMinutes(3)]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('data-in-flight-running')
        ->assertSee('Running')
        ->assertSee('Defrag C')
        ->assertSee('started 3 minutes ago')
        ->assertSeeHtml("\$dispatch('show-command', { commandId: {$command->id} })")
        ->assertDontSeeHtml('data-in-flight-pending');
})->with([
    'running' => [CommandStatus::Running, 'started_at'],
    'sent to the agent' => [CommandStatus::Sent, 'sent_at'],
]);

it('counts queued commands and names the next one', function (): void {
    commandOn($this->device, CommandStatus::Pending, 'Second In Line', ['queued_at' => now()]);
    commandOn($this->device, CommandStatus::Pending, 'First In Line', ['queued_at' => now()->subMinute()]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('data-in-flight-pending')
        ->assertSee('2 queued')
        ->assertSee('next: First In Line')
        ->assertDontSee('waiting for the device to wake');
});

it('says queued work is waiting for the device to wake when it is not online', function (array $attributes): void {
    $this->device->forceFill($attributes)->save();
    commandOn($this->device, CommandStatus::Pending, 'Patch Tuesday');

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device->fresh()])
        ->assertSee('1 queued')
        ->assertSee('waiting for the device to wake');
})->with([
    'offline' => [fn (): array => ['last_seen' => now()->subHour()]],
    'powering off' => [fn (): array => ['power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()]],
]);

it('offers Cancel on the next queued command only to whoever queued it', function (): void {
    $mine = commandOn($this->device, CommandStatus::Pending, 'Mine');

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml("cancelCommand({$mine->id})");

    Livewire::actingAs(User::factory()->create())->test(Overview::class, ['device' => $this->device])
        ->assertSee('next: Mine')
        ->assertDontSeeHtml("cancelCommand({$mine->id})");
});

it('cancels the next queued command from the strip and hides it', function (): void {
    $command = commandOn($this->device, CommandStatus::Pending, 'Second Thoughts');

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->call('cancelCommand', $command->id)
        ->assertDontSeeHtml('data-in-flight');

    expect($command->fresh()->status)->toBe(CommandStatus::Cancelled);
});

it('never shows the strip for monitor-only devices', function (): void {
    $server = Device::factory()->monitorOnly()->active()->create(['last_seen' => now()]);
    commandOn($server, CommandStatus::Pending, 'Left Over From Before');

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $server])
        ->assertDontSeeHtml('data-in-flight');
});

it('appears and clears live as commands are queued and finish', function (): void {
    $overview = Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertDontSeeHtml('data-in-flight');

    $command = commandOn($this->device, CommandStatus::Pending, 'Live One');

    $overview->dispatch('command-queued')->assertSee('1 queued');

    $command->markAsSent();
    $command->markAsRunning();

    $overview->dispatch("echo-private:devices.{$this->device->id},CommandUpdated", ['commandId' => $command->id, 'deviceId' => $this->device->id, 'status' => 'running'])
        ->assertSeeHtml('data-in-flight-running')
        ->assertDontSee('1 queued');

    $command->markAsCompleted('done', 0);

    $overview->dispatch("echo-private:devices.{$this->device->id},CommandUpdated", ['commandId' => $command->id, 'deviceId' => $this->device->id, 'status' => 'completed'])
        ->assertDontSeeHtml('data-in-flight');
});

it('keeps the overview query count flat however many commands are in flight', function (): void {
    $queriesFor = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device]);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    commandOn($this->device, CommandStatus::Running, 'Running One', ['started_at' => now()]);
    commandOn($this->device, CommandStatus::Pending, 'Queued One');
    $few = $queriesFor();

    collect(range(1, 6))->each(fn (int $index) => commandOn($this->device, CommandStatus::Pending, "Queued {$index}"));
    $many = $queriesFor();

    expect($many)->toBe($few);
});
