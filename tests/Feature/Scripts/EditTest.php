<?php

declare(strict_types=1);

use App\Livewire\Scripts\Edit;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('updates script fields', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($user)
        ->test(Edit::class, ['script' => $script])
        ->set('name', 'New Name')
        ->set('description', 'Updated description')
        ->call('save')
        ->assertHasNoErrors();

    $script->refresh();
    expect($script->name)->toBe('New Name');
    expect($script->description)->toBe('Updated description');
});

it('cannot edit system scripts', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->system()->create();

    Livewire::actingAs($user)
        ->test(Edit::class, ['script' => $script])
        ->assertForbidden();
});

it('validates on update', function (): void {
    $user = User::factory()->create();
    $script = Script::factory()->create();

    Livewire::actingAs($user)
        ->test(Edit::class, ['script' => $script])
        ->set('name', '')
        ->set('script_content', '')
        ->call('save')
        ->assertHasErrors(['name', 'script_content']);
});
