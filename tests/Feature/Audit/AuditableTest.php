<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\DeviceStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

function auditFor(AuditAction $action): AuditLog
{
    return AuditLog::query()->where('action', $action)->sole();
}

it('records a create, a meaningful update and a delete with the signed in user', function (): void {
    $this->actingAs($this->user);

    $rule = AlertRule::factory()->create(['name' => 'Hot CPU']);
    $rule->update(['is_active' => false]);
    $rule->delete();

    $created = auditFor(AuditAction::AlertRuleCreated);
    $updated = auditFor(AuditAction::AlertRuleUpdated);
    $deleted = auditFor(AuditAction::AlertRuleDeleted);

    expect([$created->user_id, $updated->user_id, $deleted->user_id])->toBe([$this->user->id, $this->user->id, $this->user->id])
        ->and($created->subject_type)->toBe(AlertRule::class)
        ->and($created->subject_id)->toBe($rule->id)
        ->and($created->properties['label'])->toBe('Hot CPU')
        ->and($created->properties['attributes']['name'])->toBe('Hot CPU')
        ->and($updated->properties['changes'])->toBe(['is_active' => ['from' => true, 'to' => false]])
        ->and($deleted->properties['attributes']['name'])->toBe('Hot CPU');
});

it('records the request IP and user agent', function (): void {
    $this->actingAs($this->user)
        ->withHeader('User-Agent', 'AuditBrowser/1.0')
        ->post(route('logout'));

    expect(auditFor(AuditAction::Logout))
        ->ip->toBe('127.0.0.1')
        ->user_agent->toBe('AuditBrowser/1.0');
});

it('skips updates that touch no audited attribute', function (): void {
    $task = ScheduledTask::factory()->create();
    $before = AuditLog::count();

    $task->update(['last_run_at' => now()]);
    $task->calculateNextRun();

    expect(AuditLog::count())->toBe($before);
});

it('writes no audit rows for a metrics post', function (): void {
    $device = Device::factory()->active()->withApiKey('KEY-AUDIT-METRICS')->create(['last_seen' => null]);
    $before = AuditLog::count();

    $this->withHeaders(['X-Device-Key' => 'KEY-AUDIT-METRICS'])
        ->postJson('/api/metrics', [
            'cpu' => 12.5,
            'ram' => 40.1,
            'agent_version' => '9.9.9',
            'mac_addresses' => ['AA-BB-CC-DD-EE-FF'],
        ])->assertSuccessful();

    expect($device->fresh()->last_seen)->not->toBeNull()
        ->and(AuditLog::count())->toBe($before);
});

it('records a device enrolling, being approved and having its enrolment reset', function (): void {
    $device = Device::factory()->create(['hostname' => 'AUDITED-PC']);

    $this->actingAs($this->user);
    $device->issueApiKey();
    $device->resetEnrolment();

    $enrolled = auditFor(AuditAction::DeviceEnrolled);
    $approved = auditFor(AuditAction::DeviceApproved);
    $reset = auditFor(AuditAction::DeviceEnrolmentReset);

    expect($enrolled->user_id)->toBeNull()
        ->and($enrolled->actorName())->toBe('Agent')
        ->and($enrolled->properties['label'])->toBe('AUDITED-PC')
        ->and($approved->user_id)->toBe($this->user->id)
        ->and($approved->properties['changes']['status'])->toBe(['from' => DeviceStatus::Pending->value, 'to' => DeviceStatus::Active->value])
        ->and($approved->properties['secrets_changed'])->toBe(['api_key_hash'])
        ->and($reset->properties['secrets_changed'])->toBe(['api_key_hash']);
});

it('never writes secrets into the audit log', function (): void {
    $this->actingAs($this->user);
    $device = Device::factory()->awaitingKeyClaim('PLAINTEXT-DEVICE-KEY')->create();
    $device->resetEnrolment();
    $device->issueApiKey();
    $this->user->update(['password' => 'brand-new-password']);

    $logged = AuditLog::query()->get()->map(fn (AuditLog $log): string => json_encode($log->properties))->implode("\n");

    expect($logged)
        ->not->toContain('PLAINTEXT-DEVICE-KEY')
        ->not->toContain(Device::hashApiKey('PLAINTEXT-DEVICE-KEY'))
        ->not->toContain((string) $device->fresh()->api_key_hash)
        ->not->toContain('brand-new-password')
        ->not->toContain($this->user->fresh()->password)
        ->not->toContain('remember_token')
        ->not->toContain('two_factor');

    expect(auditFor(AuditAction::UserPasswordChanged)->properties)
        ->not->toHaveKey('changes')
        ->secrets_changed->toBe(['password']);
});

it('keeps a hash of script content instead of the content', function (): void {
    $this->actingAs($this->user);

    $script = Script::factory()->create(['script_content' => 'Write-Output SECRET-ONE']);
    $script->update(['script_content' => 'Write-Output SECRET-TWO']);
    $script->update(['name' => 'Renamed']);

    $created = auditFor(AuditAction::ScriptCreated);
    $changed = auditFor(AuditAction::ScriptContentChanged);
    $renamed = auditFor(AuditAction::ScriptUpdated);

    expect($created->properties['attributes'])->toHaveKey('script_content_sha256', hash('sha256', 'Write-Output SECRET-ONE'))
        ->and($created->properties['attributes'])->not->toHaveKey('script_content')
        ->and($changed->properties['changes'])->toBe(['script_content_sha256' => [
            'from' => hash('sha256', 'Write-Output SECRET-ONE'),
            'to' => hash('sha256', 'Write-Output SECRET-TWO'),
        ]])
        ->and($renamed->properties['changes'])->toHaveKey('name')
        ->and(json_encode(AuditLog::pluck('properties')))->not->toContain('SECRET-');
});

it('records the full text of a command queued without a script', function (): void {
    $this->actingAs($this->user);
    $device = Device::factory()->active()->create(['hostname' => 'ADHOC-BOX']);

    $command = DeviceCommand::factory()->pending()->create([
        'device_id' => $device->id,
        'script_id' => null,
        'script_type' => 'powershell',
        'script_content' => 'Get-Process | Where-Object CPU -gt 50 | Stop-Process -Force',
    ]);

    $queued = auditFor(AuditAction::CommandQueued);

    expect($queued->subject_id)->toBe($command->id)
        ->and($queued->user_id)->toBe($this->user->id)
        ->and($queued->properties['command'])->toBe('Get-Process | Where-Object CPU -gt 50 | Stop-Process -Force')
        ->and($queued->properties['label'])->toBe('Powershell on ADHOC-BOX')
        ->and($queued->properties['attributes']['device_id'])->toBe($device->id);
});

it('records the script behind a scripted command but not its content', function (): void {
    $script = Script::factory()->create(['name' => 'Clear Temp', 'script_content' => 'Remove-Item SECRET-PATH']);
    $task = ScheduledTask::factory()->create(['script_id' => $script->id]);

    DeviceCommand::factory()->pending()->create([
        'script_id' => $script->id,
        'scheduled_task_id' => $task->id,
        'script_content' => $script->script_content,
    ]);

    $queued = auditFor(AuditAction::CommandQueued);

    expect($queued->properties['script_name'])->toBe('Clear Temp')
        ->and($queued->properties['attributes'])->toMatchArray(['script_id' => $script->id, 'scheduled_task_id' => $task->id])
        ->and($queued->properties)->not->toHaveKey('command')
        ->and(json_encode($queued->properties))->not->toContain('SECRET-PATH');
});

it('does not record command status changes', function (): void {
    $command = DeviceCommand::factory()->pending()->create();
    $before = AuditLog::count();

    $command->markAsSent();
    $command->markAsCompleted('done', 0);

    expect(AuditLog::count())->toBe($before);
});

it('records scheduled task changes including switching it off', function (): void {
    $this->actingAs($this->user);
    $task = ScheduledTask::factory()->create(['name' => 'Nightly']);

    $task->update(['is_active' => false]);

    expect(auditFor(AuditAction::ScheduledTaskCreated)->properties['attributes'])->toMatchArray(['name' => 'Nightly', 'is_active' => true])
        ->and(auditFor(AuditAction::ScheduledTaskUpdated)->properties['changes'])->toBe(['is_active' => ['from' => true, 'to' => false]]);
});

it('records people acknowledging and resolving alerts but not automatic resolves', function (): void {
    $acknowledged = Alert::factory()->triggered()->create();
    $resolvedBySomeone = Alert::factory()->triggered()->create();
    $resolvedBySystem = Alert::factory()->triggered()->create();

    $resolvedBySystem->resolve();

    $this->actingAs($this->user);
    $acknowledged->acknowledge($this->user);
    $resolvedBySomeone->resolve();

    expect(auditFor(AuditAction::AlertAcknowledged))->subject_id->toBe($acknowledged->id)->user_id->toBe($this->user->id)
        ->and(auditFor(AuditAction::AlertResolved))->subject_id->toBe($resolvedBySomeone->id)
        ->and(AuditLog::query()->where('subject_type', Alert::class)->count())->toBe(2);
});

it('records users being created and deleted', function (): void {
    $colleague = User::factory()->create(['email' => 'colleague@example.com']);
    $colleague->delete();

    expect(auditFor(AuditAction::UserDeleted)->properties['label'])->toBe('colleague@example.com')
        ->and(AuditLog::query()->where('action', AuditAction::UserCreated)->where('subject_id', $colleague->id)->exists())->toBeTrue();
});

it('records console work with no actor', function (): void {
    $this->artisan('users:create', [
        '--name' => 'Console Made',
        '--email' => 'console@example.com',
        '--password' => 'super-secret-password',
    ])->assertSuccessful();

    $created = AuditLog::query()->where('action', AuditAction::UserCreated)->latest('id')->first();

    expect($created->user_id)->toBeNull()
        ->and($created->actorName())->toBe('System')
        ->and($created->properties['attributes'])->toBe(['name' => 'Console Made', 'email' => 'console@example.com'])
        ->and(json_encode($created->properties))->not->toContain('super-secret-password');
});

it('keeps the actor email after the actor is deleted', function (): void {
    $this->actingAs($this->user);
    AlertRule::factory()->create();
    $this->user->delete();

    $created = auditFor(AuditAction::AlertRuleCreated);

    expect($created->user_id)->toBeNull()
        ->and($created->actorName())->toBe($this->user->email);
});

it('mirrors every audit row to the audit log channel', function (): void {
    $logged = [];
    Illuminate\Support\Facades\Log::listen(function ($event) use (&$logged): void {
        $logged[] = $event->message;
    });

    $this->actingAs($this->user);
    AlertRule::factory()->create();

    expect($logged)->toContain(AuditAction::AlertRuleCreated->value);
});
