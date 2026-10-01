<?php

declare(strict_types=1);

use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Livewire\Scripts\Index;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('lists scripts for authenticated users', function (): void {
    $user = User::factory()->create();
    Script::factory()->create(['name' => 'Test Script']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Test Script')
        ->assertSuccessful();
});

it('requires authentication', function (): void {
    $this->get('/scripts')->assertRedirect('/login');
});

it('searches scripts by name', function (): void {
    $user = User::factory()->create();
    Script::factory()->create(['name' => 'Disk Cleanup']);
    Script::factory()->create(['name' => 'Network Check']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('search', 'Disk')
        ->assertSee('Disk Cleanup')
        ->assertDontSee('Network Check');
});

it('filters scripts by category', function (): void {
    $user = User::factory()->create();
    Script::factory()->create(['name' => 'Power Script', 'category' => ScriptCategory::Power]);
    Script::factory()->create(['name' => 'Network Script', 'category' => ScriptCategory::Network]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('categoryFilter', ScriptCategory::Power->value)
        ->assertSee('Power Script')
        ->assertDontSee('Network Script');
});

it('filters scripts by platform', function (): void {
    $user = User::factory()->create();
    Script::factory()->create(['name' => 'Win Script', 'platform' => ScriptPlatform::Windows]);
    Script::factory()->create(['name' => 'Linux Script', 'platform' => ScriptPlatform::Linux]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('platformFilter', ScriptPlatform::Windows->value)
        ->assertSee('Win Script')
        ->assertDontSee('Linux Script');
});

it('deletes non-system scripts', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create(['is_system' => false]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('delete', $script->id);

    expect(Script::find($script->id))->toBeNull();
});

it('cannot delete system scripts', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->system()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('delete', $script->id)
        ->assertForbidden();

    expect(Script::find($script->id))->not->toBeNull();
});
