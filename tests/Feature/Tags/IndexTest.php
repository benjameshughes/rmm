<?php

declare(strict_types=1);

use App\Livewire\Tags\Index;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('lists tags with device counts', function (): void {
    $user = User::factory()->create();
    Tag::factory()->create(['name' => 'production']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('production');
});

it('creates a tag', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('name', 'critical')
        ->set('color', 'red')
        ->call('create')
        ->assertHasNoErrors();

    expect(Tag::where('name', 'critical')->exists())->toBeTrue();
});

it('validates unique tag name', function (): void {
    $user = User::factory()->create();
    Tag::factory()->create(['name' => 'critical']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('name', 'critical')
        ->call('create')
        ->assertHasErrors(['name']);
});

it('deletes a tag', function (): void {
    $user = User::factory()->create();
    $tag = Tag::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('delete', $tag->id);

    expect(Tag::find($tag->id))->toBeNull();
});

it('requires authentication', function (): void {
    $this->get('/tags')->assertRedirect('/login');
});
