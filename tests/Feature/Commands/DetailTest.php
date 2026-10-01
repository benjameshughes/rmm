<?php

declare(strict_types=1);

use App\Enums\CommandStatus;
use App\Livewire\Commands\Detail;
use App\Livewire\Devices\Show;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

function commandWithOutput(string $output, array $attributes = []): DeviceCommand
{
    return DeviceCommand::factory()->create([
        'status' => CommandStatus::Completed,
        'script_type' => 'powershell',
        'script_content' => 'hostname',
        'output' => $output,
        'exit_code' => 0,
        ...$attributes,
    ]);
}

it('opens with the full output when a command is selected', function (): void {
    $command = commandWithOutput('DESKTOP-5ULJ14E');

    Livewire::actingAs(User::factory()->create())
        ->test(Detail::class)
        ->assertSet('showModal', false)
        ->dispatch('show-command', commandId: $command->id)
        ->assertSet('showModal', true)
        ->assertSee('DESKTOP-5ULJ14E')
        ->assertSee($command->device->hostname)
        ->assertSee('Completed');
});

it('shows stderr separately from stdout', function (): void {
    $command = commandWithOutput('all good'.config('commands.stderr_separator').'something broke', [
        'status' => CommandStatus::Failed,
        'exit_code' => 1,
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(Detail::class)
        ->dispatch('show-command', commandId: $command->id)
        ->assertSeeInOrder(['Output', 'all good', 'Errors', 'something broke'])
        ->assertDontSee('--- stderr ---');
});

it('shows the agent error message for a timed out command', function (): void {
    $command = commandWithOutput('partial', [
        'status' => CommandStatus::TimedOut,
        'error_message' => 'Command timed out after 60 seconds',
        'exit_code' => -1,
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(Detail::class)
        ->dispatch('show-command', commandId: $command->id)
        ->assertSee('Timed Out')
        ->assertSee('Command timed out after 60 seconds');
});

it('copes with a command that has since been deleted', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test(Detail::class)
        ->dispatch('show-command', commandId: 999)
        ->assertSee('That command no longer exists.');
});

it('splits output into stdout and stderr', function (): void {
    $separator = config('commands.stderr_separator');

    expect(commandWithOutput('hello')->stdout())->toBe('hello');
    expect(commandWithOutput('hello')->stderr())->toBeNull();
    expect(commandWithOutput("hello{$separator}oops")->stdout())->toBe('hello');
    expect(commandWithOutput("hello{$separator}oops")->stderr())->toBe('oops');
    expect(commandWithOutput('')->stdout())->toBe('');
});

it('names a command after its script, falling back to the script type', function (): void {
    $script = Script::factory()->create(['name' => 'Get Hostname']);

    expect(commandWithOutput('', ['script_id' => $script->id])->displayName())->toBe('Get Hostname');
    expect(commandWithOutput('', ['script_type' => 'powershell'])->displayName())->toBe('Powershell');
});

it('reports how long a command took', function (): void {
    $command = commandWithOutput('', [
        'started_at' => now()->subSeconds(75),
        'completed_at' => now(),
    ]);

    expect($command->durationForHumans())->toBe('1m 15s');
    expect(commandWithOutput('', ['started_at' => null, 'sent_at' => null])->durationForHumans())->toBeNull();
});

it('gives every command status a badge colour', function (CommandStatus $status): void {
    expect($status->color())->toBeString()->not->toBeEmpty();
})->with(CommandStatus::cases());

it('lists commands on the device page without a query per row', function (): void {
    $device = Device::factory()->active()->create();
    $script = Script::factory()->create(['name' => 'Get Hostname']);
    DeviceCommand::factory()->count(5)->create([
        'device_id' => $device->id,
        'script_id' => $script->id,
        'queued_by' => User::factory(),
    ]);

    DB::enableQueryLog();

    Livewire::actingAs(User::factory()->create())
        ->test(Show::class, ['device' => $device])
        ->assertSee('Get Hostname')
        ->assertSee('show-command', false);

    $scriptQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "scripts" where "scripts"."id" ='));
    expect($scriptQueries)->toBeEmpty();
});
