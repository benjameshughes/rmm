<?php

declare(strict_types=1);

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
