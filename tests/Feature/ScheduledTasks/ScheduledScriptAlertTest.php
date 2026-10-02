<?php

declare(strict_types=1);

use App\Actions\Schedule\RunScheduledTask;
use App\Actions\Script\ExecuteScriptOnDevice;
use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Livewire\AlertRules\Index as AlertRulesIndex;
use App\Livewire\Alerts\Index as AlertsIndex;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\User;
use App\Notifications\AlertTriggered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->device = Device::factory()->active()->withApiKey('SCHEDULE-KEY')->create(['hostname' => 'PC-1']);
    $this->task = ScheduledTask::factory()->create([
        'name' => 'Patch status',
        'target_type' => 'device',
        'target_id' => $this->device->id,
    ]);
});

/**
 * Queue the task's script on the test device the way the scheduler does.
 */
function queueScheduledCommand(ScheduledTask $task): DeviceCommand
{
    app(RunScheduledTask::class)($task);

    return DeviceCommand::query()->where('scheduled_task_id', $task->id)->latest('id')->firstOrFail();
}

/**
 * @return Illuminate\Database\Eloquent\Collection<int, Alert>
 */
function scheduledScriptAlerts()
{
    return Alert::query()->where('metric', AlertMetric::ScriptFailed)->get();
}

it('links commands queued by a schedule to it', function (): void {
    $command = queueScheduledCommand($this->task);

    expect($command->scheduled_task_id)->toBe($this->task->id)
        ->and($command->scheduledTask->is($this->task))->toBeTrue();
});

it('leaves manually run commands unlinked', function (): void {
    $command = app(ExecuteScriptOnDevice::class)(Script::factory()->create(), $this->device, User::factory()->create());

    expect($command->scheduled_task_id)->toBeNull();
});

it('raises one alert carrying the summary line when a scheduled script exits non-zero', function (): void {
    $command = queueScheduledCommand($this->task);

    $command->markAsCompleted("\r\n  3 updates pending, last installed 40 days ago  \r\nKB5031234\r\n", 1);

    $alert = scheduledScriptAlerts()->sole();

    expect($alert->device_id)->toBe($this->device->id)
        ->and($alert->scheduled_task_id)->toBe($this->task->id)
        ->and($alert->alert_rule_id)->toBe(AlertRule::scheduledScriptFailed()->id)
        ->and($alert->status)->toBe(AlertStatus::Triggered)
        ->and($alert->message)->toBe('PC-1: Patch status: 3 updates pending, last installed 40 days ago');
});

it('raises an alert when the agent reports the scheduled script failed', function (): void {
    $command = queueScheduledCommand($this->task);

    $this->withHeaders(['X-Agent-Key' => 'SCHEDULE-KEY'])
        ->postJson("/api/commands/{$command->id}/result", [
            'exit_code' => 1,
            'output' => '2 critical errors in the System log',
            'error_message' => 'Script exited with code 1',
        ])
        ->assertSuccessful();

    expect(scheduledScriptAlerts()->sole()->message)->toBe('PC-1: Patch status: 2 critical errors in the System log');
});

it('raises an alert when a scheduled script times out', function (): void {
    $command = queueScheduledCommand($this->task);
    $command->update(['status' => 'running', 'started_at' => now()->subDay(), 'timeout_seconds' => 60]);

    $this->artisan('commands:expire-stale')->assertSuccessful();

    expect(scheduledScriptAlerts()->sole()->message)->toBe('PC-1: Patch status: Command timed out after 60 seconds');
});

it('summarises from stderr, then the status, when stdout is empty', function (?string $output, ?string $errorMessage, string $summary): void {
    $command = queueScheduledCommand($this->task);

    $command->update(['status' => 'running']);
    $command->update(['status' => 'failed', 'output' => $output, 'error_message' => $errorMessage, 'exit_code' => 1]);

    expect(scheduledScriptAlerts()->sole()->message)->toBe("PC-1: Patch status: {$summary}");
})->with([
    'stderr only' => ["\n\n--- stderr ---\nAccess is denied.\nmore", null, 'Access is denied.'],
    'error message only' => [null, 'Agent crashed', 'Agent crashed'],
    'nothing at all' => [null, null, 'Failed'],
]);

it('cuts long summary lines to the configured length', function (): void {
    config(['alerts.scheduled_script_failed.summary_max_length' => 10]);
    $command = queueScheduledCommand($this->task);

    $command->markAsCompleted(str_repeat('x', 50), 1);

    expect(scheduledScriptAlerts()->sole()->message)->toBe('PC-1: Patch status: xxxxxxxxxx...');
});

it('updates the open alert instead of raising another on repeat failures', function (): void {
    queueScheduledCommand($this->task)->markAsCompleted('3 updates pending', 1);
    queueScheduledCommand($this->task)->markAsCompleted('5 updates pending', 1);

    expect(scheduledScriptAlerts()->sole()->message)->toBe('PC-1: Patch status: 5 updates pending');
});

it('resolves the open alert when the scheduled script next exits 0', function (): void {
    queueScheduledCommand($this->task)->markAsCompleted('3 updates pending', 1);
    queueScheduledCommand($this->task)->markAsCompleted('Up to date', 0);

    expect(scheduledScriptAlerts()->sole()->status)->toBe(AlertStatus::Resolved);
});

it('raises nothing when a scheduled script succeeds', function (): void {
    queueScheduledCommand($this->task)->markAsCompleted('Up to date', 0);

    expect(scheduledScriptAlerts())->toBeEmpty();
});

it('ignores cancelled scheduled commands', function (): void {
    queueScheduledCommand($this->task)->cancel();

    expect(scheduledScriptAlerts())->toBeEmpty();
});

it('keeps separate alerts for different schedules on the same device', function (): void {
    $otherTask = ScheduledTask::factory()->create([
        'name' => 'Event log',
        'target_type' => 'device',
        'target_id' => $this->device->id,
    ]);

    queueScheduledCommand($this->task)->markAsCompleted('3 updates pending', 1);
    queueScheduledCommand($otherTask)->markAsCompleted('2 critical errors', 1);
    queueScheduledCommand($otherTask)->markAsCompleted('All clear', 0);

    $alerts = scheduledScriptAlerts()->keyBy('scheduled_task_id');

    expect($alerts)->toHaveCount(2)
        ->and($alerts[$this->task->id]->status)->toBe(AlertStatus::Triggered)
        ->and($alerts[$otherTask->id]->status)->toBe(AlertStatus::Resolved);
});

it('raises nothing while the built-in rule is switched off but still resolves', function (): void {
    queueScheduledCommand($this->task)->markAsCompleted('3 updates pending', 1);
    AlertRule::scheduledScriptFailed()->update(['is_active' => false]);

    queueScheduledCommand($this->task)->markAsCompleted('Up to date', 0);
    queueScheduledCommand($this->task)->markAsCompleted('4 updates pending', 1);

    expect(scheduledScriptAlerts()->sole()->status)->toBe(AlertStatus::Resolved);
});

it('never alerts on a failed manual run', function (): void {
    $command = app(ExecuteScriptOnDevice::class)(Script::factory()->create(), $this->device, User::factory()->create());

    $command->markAsCompleted('3 updates pending', 1);

    expect(Alert::query()->count())->toBe(0);
});

it('notifies users when a scheduled script alert is raised', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    queueScheduledCommand($this->task)->markAsCompleted('3 updates pending', 1);

    Notification::assertSentTo($user, AlertTriggered::class, fn (AlertTriggered $notification): bool => $notification->alert->metric === AlertMetric::ScriptFailed
        && $notification->toDatabase($user)['condition'] === 'Scheduled Script Failed'
        && $notification->toDatabase($user)['message'] === 'PC-1: Patch status: 3 updates pending');
});

it('removes the alerts when the schedule is deleted', function (): void {
    $command = queueScheduledCommand($this->task);
    $command->markAsCompleted('3 updates pending', 1);

    $this->task->delete();

    expect(scheduledScriptAlerts())->toBeEmpty()
        ->and($command->fresh()->scheduled_task_id)->toBeNull();
});

it('renders a scheduled script alert with its message', function (): void {
    queueScheduledCommand($this->task)->markAsCompleted('3 updates pending', 1);

    Livewire::actingAs(User::factory()->create())->test(AlertsIndex::class)
        ->assertSee('Scheduled Script Failed')
        ->assertSee('PC-1: Patch status: 3 updates pending');
});

it('shows the built-in rule under alert rules without edit or delete', function (): void {
    AlertRule::scheduledScriptFailed();

    Livewire::actingAs(User::factory()->create())->test(AlertRulesIndex::class)
        ->assertSee('Scheduled script failed')
        ->assertSee('Built-in')
        ->assertDontSee('Edit')
        ->call('delete', AlertRule::scheduledScriptFailed()->id)
        ->assertForbidden();
});
