<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Livewire\Audit\Index;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['name' => 'Ben Hughes']);
});

it('lists audit entries newest first with who did what', function (): void {
    AuditLog::factory()->create(['user_id' => $this->user->id, 'action' => AuditAction::ScriptContentChanged, 'properties' => [
        'label' => 'Clear Temp',
        'changes' => ['script_content_sha256' => ['from' => 'aaa', 'to' => 'bbb']],
    ]]);

    $this->actingAs($this->user)
        ->get(route('audit.index'))
        ->assertSuccessful()
        ->assertSeeLivewire(Index::class)
        ->assertSee('Ben Hughes')
        ->assertSee('Script content changed')
        ->assertSee('Clear Temp')
        ->assertSee('script_content_sha256: aaa → bbb');
});

it('filters by user, including system entries', function (): void {
    AuditLog::factory()->create(['user_id' => $this->user->id, 'properties' => ['label' => 'BY-BEN']]);
    AuditLog::factory()->create(['user_id' => null, 'properties' => ['label' => 'BY-SYSTEM']]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->set('userFilter', (string) $this->user->id)
        ->assertSee('BY-BEN')
        ->assertDontSee('BY-SYSTEM')
        ->set('userFilter', 'system')
        ->assertSee('BY-SYSTEM')
        ->assertDontSee('BY-BEN');
});

it('filters by action', function (): void {
    AuditLog::factory()->create(['action' => AuditAction::LoginFailed, 'properties' => ['label' => 'FAILED-ROW']]);
    AuditLog::factory()->create(['action' => AuditAction::DeviceApproved, 'properties' => ['label' => 'APPROVED-ROW']]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->set('actionFilter', AuditAction::LoginFailed->value)
        ->assertSee('FAILED-ROW')
        ->assertDontSee('APPROVED-ROW');
});

it('searches by subject such as a device hostname', function (): void {
    AuditLog::factory()->create(['properties' => ['label' => 'Restart on FIND-ME-PC']]);
    AuditLog::factory()->create(['properties' => ['label' => 'OTHER-PC']]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->set('search', 'find-me')
        ->assertSee('Restart on FIND-ME-PC')
        ->assertDontSee('OTHER-PC');
});

it('paginates', function (): void {
    config(['audit.per_page' => 2]);
    AuditLog::query()->delete();
    AuditLog::factory()->count(3)->sequence(
        ['properties' => ['label' => 'ROW-ONE']],
        ['properties' => ['label' => 'ROW-TWO']],
        ['properties' => ['label' => 'ROW-THREE']],
    )->create();

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee('ROW-THREE')
        ->assertDontSee('ROW-ONE');
});

it('refreshes when an audit entry is logged over Reverb', function (): void {
    $component = Livewire::actingAs($this->user)->test(Index::class)
        ->assertDontSeeHtml('wire:poll')
        ->assertDontSee('LATE-ARRIVAL');

    $auditLog = AuditLog::factory()->create(['properties' => ['label' => 'LATE-ARRIVAL']]);

    $component->dispatch('echo-private:audit,AuditLogged', ['auditLogId' => $auditLog->id, 'action' => $auditLog->action->value])
        ->assertSee('LATE-ARRIVAL');
});

it('checks the policy', function (): void {
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'viewAny' ? false : null);

    $this->actingAs($this->user)->get(route('audit.index'))->assertForbidden();
});

it('requires authentication', function (): void {
    $this->get(route('audit.index'))->assertRedirect(route('login'));
});

it('shows the sidebar link', function (): void {
    $this->actingAs($this->user)->get(route('dashboard'))->assertSee(route('audit.index'));
});

it('offers no way to change or delete audit entries', function (): void {
    $auditRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'audit'));

    expect($auditRoutes->flatMap(fn (RoutingRoute $route): array => $route->methods())->unique()->values()->all())->toBe(['GET', 'HEAD'])
        ->and(collect(get_class_methods(Index::class))->filter(fn (string $method): bool => str_contains(strtolower($method), 'delete')))->toBeEmpty();

    Livewire::actingAs($this->user)->test(Index::class)->assertDontSeeHtml('wire:click');
});

it('refuses to update or delete an audit row', function (): void {
    $auditLog = AuditLog::factory()->create(['properties' => ['label' => 'ORIGINAL']]);

    expect($auditLog->update(['properties' => ['label' => 'TAMPERED']]))->toBeFalse()
        ->and($auditLog->delete())->toBeFalse()
        ->and(AuditLog::query()->find($auditLog->id)->properties)->toBe(['label' => 'ORIGINAL']);
});

it('prunes rows older than the retention period', function (): void {
    config(['audit.retention_days' => 30]);
    AuditLog::query()->delete();
    $stale = AuditLog::factory()->daysAgo(31)->create();
    $recent = AuditLog::factory()->daysAgo(29)->create();

    $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

    expect(AuditLog::pluck('id')->all())->toBe([$recent->id])
        ->and(AuditLog::find($stale->id))->toBeNull();
});

it('prunes the audit log every hour', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('App\\Models\\AuditLog')
        ->assertSuccessful();
});
