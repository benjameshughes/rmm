<?php

declare(strict_types=1);

use App\Enums\ScheduledTaskAction;
use App\Livewire\ScheduledTasks\Index;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('lists scheduled tasks', function (): void {
    $user = User::factory()->create();
    $task = ScheduledTask::factory()->create(['name' => 'Daily Cleanup']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Daily Cleanup');
});

it('creates a scheduled task', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('name', 'Nightly Backup')
        ->set('script_id', $script->id)
        ->set('cron_expression', '0 2 * * *')
        ->set('target_type', 'all')
        ->call('create')
        ->assertHasNoErrors();

    $task = ScheduledTask::where('name', 'Nightly Backup')->first();
    expect($task)->not->toBeNull();
    expect($task->created_by)->toBe($user->id);
    expect($task->next_run_at)->not->toBeNull();
});

it('toggles active status', function (): void {
    $user = User::factory()->create();
    $task = ScheduledTask::factory()->create(['is_active' => true]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('toggleActive', $task->id);

    expect(ScheduledTask::find($task->id)->is_active)->toBeFalse();
});

it('deletes a scheduled task', function (): void {
    $user = User::factory()->create();
    $task = ScheduledTask::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('delete', $task->id);

    expect(ScheduledTask::find($task->id))->toBeNull();
});

it('runs a task manually', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();
    $task = ScheduledTask::factory()->create([
        'script_id' => $script->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runNow', $task->id)
        ->assertDispatched('command-queued');

    $task->refresh();
    expect($task->last_run_at)->not->toBeNull();
});

it('requires authentication', function (): void {
    $this->get('/scheduled-tasks')->assertRedirect('/login');
});

it('rejects an invalid cron expression', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('name', 'Broken')
        ->set('script_id', $script->id)
        ->set('cron_expression', 'every tuesday-ish')
        ->set('target_type', 'all')
        ->call('create')
        ->assertHasErrors(['cron_expression']);

    expect(ScheduledTask::count())->toBe(0);
});

it('rejects an unknown target type', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('name', 'Broken')
        ->set('script_id', $script->id)
        ->set('target_type', 'everything')
        ->call('create')
        ->assertHasErrors(['target_type']);
});

it('requires a target when not targeting all devices', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('name', 'Group job')
        ->set('script_id', $script->id)
        ->set('target_type', 'group')
        ->call('create')
        ->assertHasErrors(['target_id']);
});

it('creates a script schedule with the run script action', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('name', 'Patch status')
        ->set('action', 'run_script')
        ->set('script_id', $script->id)
        ->call('create')
        ->assertHasNoErrors();

    $task = ScheduledTask::query()->sole();
    expect($task->action)->toBe(ScheduledTaskAction::RunScript)
        ->and($task->script_id)->toBe($script->id);
});

it('creates a wake schedule without a script', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('script_id', $script->id)
        ->set('name', 'Morning wake')
        ->set('action', 'wake')
        ->set('cron_expression', '0 7 * * 1-5')
        ->call('create')
        ->assertHasNoErrors();

    $task = ScheduledTask::query()->sole();
    expect($task->action)->toBe(ScheduledTaskAction::Wake)
        ->and($task->script_id)->toBeNull()
        ->and($task->next_run_at)->not->toBeNull();
});

it('requires a script for script schedules with a human message', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test(Index::class)
        ->set('name', 'No script')
        ->set('action', 'run_script')
        ->call('create')
        ->assertHasErrors(['script_id' => 'Choose a script to run.']);

    expect(ScheduledTask::count())->toBe(0);
});

it('rejects an unknown action', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test(Index::class)
        ->set('name', 'Broken')
        ->set('action', 'explode')
        ->call('create')
        ->assertHasErrors(['action' => 'Choose what this schedule should do.']);
});

it('shows the script picker only for script runs', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test(Index::class)
        ->assertSeeHtml('wire:model="script_id"')
        ->set('action', 'wake')
        ->assertDontSeeHtml('wire:model="script_id"');
});

it('lists the action and the script when there is one', function (): void {
    $script = Script::factory()->create(['name' => 'Patch Status Script']);
    ScheduledTask::factory()->create(['name' => 'Patches', 'script_id' => $script->id]);
    ScheduledTask::factory()->wake()->create(['name' => 'Morning wake']);

    Livewire::actingAs(User::factory()->create())
        ->test(Index::class)
        ->assertSee('Run Script')
        ->assertSee('Patch Status Script')
        ->assertSee('Wake Devices')
        ->assertSee('Morning wake');
});

it('switches an existing schedule to wake and drops its script', function (): void {
    $task = ScheduledTask::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(Index::class)
        ->call('edit', $task->id)
        ->assertSet('action', 'run_script')
        ->set('action', 'wake')
        ->call('update')
        ->assertHasNoErrors();

    $task->refresh();
    expect($task->action)->toBe(ScheduledTaskAction::Wake)
        ->and($task->script_id)->toBeNull();
});

it('checks the scheduled task policy for every action', function (string $ability, Closure $act): void {
    $user = User::factory()->create();
    $task = ScheduledTask::factory()->create(['name' => 'Guarded']);
    Illuminate\Support\Facades\Gate::before(fn (User $user, string $checked): ?bool => $checked === $ability ? false : null);

    $act(Livewire::actingAs($user)->test(Index::class), $task)->assertForbidden();

    expect(ScheduledTask::query()->whereKey($task->id)->exists())->toBeTrue();
})->with([
    'create' => ['create', fn ($page) => $page->set('name', 'Sneaky')->set('script_id', Script::factory()->create()->id)->call('create')],
    'edit' => ['update', fn ($page, ScheduledTask $task) => $page->call('edit', $task->id)],
    'toggle' => ['update', fn ($page, ScheduledTask $task) => $page->call('toggleActive', $task->id)],
    'run now' => ['run', fn ($page, ScheduledTask $task) => $page->call('runNow', $task->id)],
    'delete' => ['delete', fn ($page, ScheduledTask $task) => $page->call('delete', $task->id)],
]);

it('refuses the schedules page when viewing is not allowed', function (): void {
    Illuminate\Support\Facades\Gate::before(fn (User $user, string $checked): ?bool => $checked === 'viewAny' ? false : null);

    Livewire::actingAs(User::factory()->create())->test(Index::class)->assertForbidden();
});
