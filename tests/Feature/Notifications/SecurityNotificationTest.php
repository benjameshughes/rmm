<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Livewire\AlertBell;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\User;
use App\Notifications\AlertTriggered;
use App\Notifications\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->withoutTwoFactor()->create(['email' => 'ben@example.com']);
    $this->colleague = User::factory()->create();
});

function assertSecurityEventSent(array $users, AuditAction $action): void
{
    Notification::assertSentTo($users, SecurityEvent::class, fn (SecurityEvent $notification): bool => $notification->auditLog->action === $action);
}

it('rings every auditor for a sign-in from a new device', function (): void {
    Notification::fake();

    $this->post(route('login.store'), ['email' => 'ben@example.com', 'password' => 'password']);

    assertSecurityEventSent([$this->user, $this->colleague], AuditAction::LoginFromNewDevice);
});

it('stays quiet for a repeat sign-in from a known IP and browser', function (): void {
    $this->post(route('login.store'), ['email' => 'ben@example.com', 'password' => 'password']);
    $this->post(route('logout'));
    Notification::fake();

    $this->post(route('login.store'), ['email' => 'ben@example.com', 'password' => 'password']);

    Notification::assertNotSentTo([$this->user, $this->colleague], SecurityEvent::class);
});

it('rings for a failed sign-in', function (): void {
    Notification::fake();

    $this->post(route('login.store'), ['email' => 'ben@example.com', 'password' => 'not-the-password']);

    assertSecurityEventSent([$this->user, $this->colleague], AuditAction::LoginFailed);
});

it('rings when script content changes but not for other script edits', function (): void {
    $script = Script::factory()->create(['script_content' => 'Write-Output one']);
    Notification::fake();

    $script->update(['name' => 'Renamed']);
    Notification::assertNothingSent();

    $script->update(['script_content' => 'Write-Output two']);
    assertSecurityEventSent([$this->user, $this->colleague], AuditAction::ScriptContentChanged);
});

it('rings when a scheduled task is created or updated', function (): void {
    Notification::fake();

    $task = ScheduledTask::factory()->create();
    assertSecurityEventSent([$this->user, $this->colleague], AuditAction::ScheduledTaskCreated);

    $task->update(['cron_expression' => '*/5 * * * *']);
    assertSecurityEventSent([$this->user, $this->colleague], AuditAction::ScheduledTaskUpdated);
});

it('rings existing users when a user is created, but not the new user', function (): void {
    Notification::fake();

    $newcomer = User::factory()->create();

    assertSecurityEventSent([$this->user, $this->colleague], AuditAction::UserCreated);
    Notification::assertNotSentTo($newcomer, SecurityEvent::class);
});

it('stays quiet for everyday actions', function (): void {
    $device = Device::factory()->create();
    Notification::fake();

    $this->actingAs($this->user);
    $device->issueApiKey();
    $this->post(route('logout'));

    Notification::assertNothingSent();
});

it('only rings users allowed to read the audit log', function (): void {
    Notification::fake();
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'viewAny' && $user->is($this->colleague) ? false : null);

    $this->post(route('login.store'), ['email' => 'ben@example.com', 'password' => 'not-the-password']);

    Notification::assertSentTo($this->user, SecurityEvent::class);
    Notification::assertNotSentTo($this->colleague, SecurityEvent::class);
});

it('stores the bell fields and keeps details off the socket', function (): void {
    $this->post(route('login.store'), ['email' => 'ben@example.com', 'password' => 'not-the-password']);

    $notification = $this->colleague->notifications()->where('type', SecurityEvent::class)->sole();
    $auditLog = AuditLog::query()->where('action', AuditAction::LoginFailed)->sole();
    $payload = (new SecurityEvent($auditLog))->toBroadcast($this->colleague)->data;

    expect($notification->data)->toMatchArray([
        'auditLogId' => $auditLog->id,
        'title' => 'Failed sign-in',
        'level' => 'warning',
        'url' => route('audit.index'),
    ])
        ->and($notification->data['body'])->toContain('ben@example.com')
        ->and($payload)->toBe(['auditLogId' => $auditLog->id, 'action' => AuditAction::LoginFailed->value])
        ->and(json_encode($payload))->not->toContain('ben@example.com')
        ->and(json_encode($payload))->not->toContain('127.0.0.1');
});

it('shows security and alert notifications in the bell', function (): void {
    $device = Device::factory()->create(['hostname' => 'ALERTING-BOX']);
    Alert::factory()->triggered()->create(['device_id' => $device->id]);
    $this->post(route('login.store'), ['email' => 'ben@example.com', 'password' => 'not-the-password']);

    Livewire::withoutLazyLoading()->actingAs($this->colleague)->test(AlertBell::class)
        ->assertViewHas('unreadCount', 2)
        ->assertSee('ALERTING-BOX')
        ->assertSee('Failed sign-in');
});

it('still shows alert notifications stored before the bell fields existed', function (): void {
    $device = Device::factory()->create();
    $this->colleague->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => AlertTriggered::class,
        'data' => [
            'alertId' => 1,
            'deviceId' => $device->id,
            'hostname' => 'LEGACY-BOX',
            'severity' => 'critical',
            'condition' => 'CPU > 90%',
            'message' => 'CPU at 97%',
        ],
    ]);

    $notification = $this->colleague->notifications()->where('type', AlertTriggered::class)->sole();

    Livewire::withoutLazyLoading()->actingAs($this->colleague)->test(AlertBell::class)
        ->assertSee('LEGACY-BOX')
        ->assertSee('CPU &gt; 90%', false)
        ->assertSee('Critical')
        ->call('open', $notification->id)
        ->assertRedirect(route('devices.show', $device->id));
});

it('opens a security notification on the audit page', function (): void {
    $this->post(route('login.store'), ['email' => 'ben@example.com', 'password' => 'not-the-password']);
    $notification = $this->colleague->notifications()->where('type', SecurityEvent::class)->sole();

    Livewire::withoutLazyLoading()->actingAs($this->colleague)->test(AlertBell::class)
        ->call('open', $notification->id)
        ->assertRedirect(route('audit.index'));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('toasts a security notification that arrives over Reverb', function (): void {
    $component = Livewire::withoutLazyLoading()->actingAs($this->colleague)->test(AlertBell::class, ['showsToasts' => true]);

    AuditLog::factory()->create(['action' => AuditAction::LoginFailed, 'properties' => ['label' => 'ben@example.com', 'email' => 'ben@example.com']]);
    $notification = $this->colleague->notifications()->where('type', SecurityEvent::class)->sole();

    $component->dispatch("echo-private:App.Models.User.{$this->colleague->id},.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated", ['id' => $notification->id])
        ->assertSee('Failed sign-in')
        ->assertDispatched('toast-show');
});
