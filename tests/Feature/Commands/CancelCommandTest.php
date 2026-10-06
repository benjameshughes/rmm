<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\AuditAction;
use App\Enums\CommandStatus;
use App\Events\CommandUpdated;
use App\Livewire\Commands\Detail;
use App\Livewire\Devices\Commands;
use App\Livewire\Devices\InFlight;
use App\Livewire\Scripts\Show as ScriptsShow;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->withApiKey('CANCEL-KEY')->create(['hostname' => 'CANCEL-PC']);
    $this->script = Script::factory()->create(['name' => 'Clear Temp']);
});

function queuedCommand(User $user, array $attributes = []): DeviceCommand
{
    return DeviceCommand::factory()->pending()->create([
        'device_id' => test()->device->id,
        'script_id' => test()->script->id,
        'queued_by' => $user->id,
        ...$attributes,
    ]);
}

function cancelButton(DeviceCommand $command): string
{
    return "cancelCommand({$command->id})";
}

/**
 * Each surface that lists commands, mounted with the command on screen.
 *
 * @return array<string, Closure(DeviceCommand): Livewire\Features\SupportTesting\Testable>
 */
function commandSurfaces(): array
{
    return [
        'device commands tab' => fn (DeviceCommand $command) => Livewire::test(Commands::class, ['device' => $command->device]),
        'device in-flight strip' => fn (DeviceCommand $command) => Livewire::test(InFlight::class, ['device' => $command->device]),
        'script page' => fn (DeviceCommand $command) => Livewire::test(ScriptsShow::class, ['script' => $command->script]),
        'command detail' => fn (DeviceCommand $command) => Livewire::test(Detail::class)->dispatch('show-command', commandId: $command->id),
    ];
}

it('shows Cancel on your own pending command', function (string $surface): void {
    $command = queuedCommand($this->user);
    $this->actingAs($this->user);

    commandSurfaces()[$surface]($command)
        ->assertSeeHtml(cancelButton($command))
        ->assertSeeHtml('Cancel this command before it runs?');
})->with(['device commands tab', 'device in-flight strip', 'script page', 'command detail']);

it('hides Cancel on a pending command someone else queued', function (string $surface): void {
    $command = queuedCommand(User::factory()->create());
    $this->actingAs($this->user);

    commandSurfaces()[$surface]($command)->assertDontSeeHtml(cancelButton($command));
})->with(['device commands tab', 'device in-flight strip', 'script page', 'command detail']);

it('hides Cancel once the agent has fetched your command', function (string $surface): void {
    $command = queuedCommand($this->user, ['status' => CommandStatus::Sent, 'sent_at' => now()]);
    $this->actingAs($this->user);

    commandSurfaces()[$surface]($command)->assertDontSeeHtml(cancelButton($command));
})->with(['device commands tab', 'device in-flight strip', 'script page', 'command detail']);

it('cancels your pending command, broadcasts it and confirms', function (string $surface): void {
    $command = queuedCommand($this->user);
    $this->actingAs($this->user);
    Event::fake([CommandUpdated::class]);

    commandSurfaces()[$surface]($command)
        ->call('cancelCommand', $command->id)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => $params['slots']['heading'] === 'Command cancelled'
            && $params['dataset']['variant'] === 'success')
        ->assertDontSeeHtml(cancelButton($command));

    expect($command->fresh())
        ->status->toBe(CommandStatus::Cancelled)
        ->completed_at->not->toBeNull();

    Event::assertDispatched(CommandUpdated::class, fn (CommandUpdated $event): bool => $event->commandId === $command->id
        && $event->status === CommandStatus::Cancelled->value);
})->with(['device commands tab', 'device in-flight strip', 'script page', 'command detail']);

it('refuses to cancel a pending command someone else queued', function (): void {
    $command = queuedCommand(User::factory()->create());
    Event::fake([CommandUpdated::class]);

    Livewire::actingAs($this->user)
        ->test(Commands::class, ['device' => $this->device])
        ->call('cancelCommand', $command->id)
        ->assertForbidden();

    expect($command->fresh())
        ->status->toBe(CommandStatus::Pending)
        ->completed_at->toBeNull();
    Event::assertNotDispatched(CommandUpdated::class);
    expect(AuditLog::query()->where('action', AuditAction::CommandCancelled)->exists())->toBeFalse();
});

it('leaves a command the agent fetched after the page rendered and says it already started', function (): void {
    $command = queuedCommand($this->user);

    $component = Livewire::actingAs($this->user)
        ->test(Commands::class, ['device' => $this->device])
        ->assertSeeHtml(cancelButton($command));

    $command->markAsSent();

    $component->call('cancelCommand', $command->id)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => $params['slots']['heading'] === 'Command already started'
            && $params['dataset']['variant'] === 'warning');

    expect($command->fresh())
        ->status->toBe(CommandStatus::Sent)
        ->completed_at->toBeNull();
});

it('never overwrites a fetch that lands after the pending check', function (): void {
    $stale = queuedCommand($this->user);
    DeviceCommand::query()->findOrFail($stale->id)->markAsSent();

    expect($stale->isPending())->toBeTrue()
        ->and($stale->cancel())->toBeFalse()
        ->and($stale->status)->toBe(CommandStatus::Sent)
        ->and($stale->fresh()->status)->toBe(CommandStatus::Sent);
});

it('never hands a cancelled command to the agent', function (): void {
    queuedCommand($this->user)->cancel();

    $this->withHeaders(['X-Agent-Key' => 'CANCEL-KEY'])
        ->getJson('/api/commands/pending')
        ->assertSuccessful()
        ->assertJson(['command' => null]);
});

it('records the cancel against the user in the audit log', function (): void {
    $command = queuedCommand($this->user);

    Livewire::actingAs($this->user)
        ->test(Commands::class, ['device' => $this->device])
        ->call('cancelCommand', $command->id);

    $audit = AuditLog::query()->where('action', AuditAction::CommandCancelled)->sole();

    expect($audit->user_id)->toBe($this->user->id)
        ->and($audit->subject_type)->toBe(DeviceCommand::class)
        ->and($audit->subject_id)->toBe($command->id)
        ->and($audit->properties['label'])->toBe('Clear Temp on CANCEL-PC')
        ->and($audit->properties['changes'])->toBe(['status' => ['from' => 'pending', 'to' => 'cancelled']]);
});
