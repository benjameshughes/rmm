<?php

declare(strict_types=1);

use App\Livewire\AlertRules\Index;
use App\Models\AlertRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('lists alert rules', function (): void {
    $user = User::factory()->create();
    AlertRule::factory()->create(['name' => 'High CPU']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('High CPU');
});

it('creates a rule', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('name', 'Test Rule')
        ->set('metric', 'cpu')
        ->set('operator', 'gt')
        ->set('threshold', 85)
        ->set('duration_minutes', 5)
        ->set('severity', 'warning')
        ->call('create')
        ->assertHasNoErrors();

    expect(AlertRule::where('name', 'Test Rule')->exists())->toBeTrue();
});

it('toggles active status', function (): void {
    $user = User::factory()->create();
    $rule = AlertRule::factory()->create(['is_active' => true]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('toggleActive', $rule->id);

    expect(AlertRule::find($rule->id)->is_active)->toBeFalse();
});

it('deletes a rule', function (): void {
    $user = User::factory()->create();
    $rule = AlertRule::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('delete', $rule->id);

    expect(AlertRule::find($rule->id))->toBeNull();
});

it('requires authentication', function (): void {
    $this->get('/alert-rules')->assertRedirect('/login');
});

it('rejects unknown metric, operator and severity values', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('name', 'Nonsense')
        ->set('metric', 'vibes')
        ->set('operator', 'roughly')
        ->set('severity', 'apocalyptic')
        ->call('create')
        ->assertHasErrors(['metric', 'operator', 'severity']);

    expect(AlertRule::count())->toBe(0);
});
