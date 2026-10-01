<?php

declare(strict_types=1);

use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use App\Livewire\Scripts\Create;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('creates a script with valid data', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('name', 'Test Script')
        ->set('description', 'A test script')
        ->set('category', ScriptCategory::Info->value)
        ->set('platform', ScriptPlatform::Windows->value)
        ->set('script_type', ScriptType::Powershell->value)
        ->set('script_content', 'Get-Process')
        ->set('timeout_seconds', 120)
        ->set('requires_admin', false)
        ->call('save')
        ->assertHasNoErrors();

    $script = Script::where('name', 'Test Script')->first();

    expect($script)->not->toBeNull();
    expect($script->category)->toBe(ScriptCategory::Info);
    expect($script->platform)->toBe(ScriptPlatform::Windows);
    expect($script->script_type)->toBe(ScriptType::Powershell);
    expect($script->is_system)->toBeFalse();
    expect($script->timeout_seconds)->toBe(120);
});

it('validates required fields', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('name', '')
        ->set('category', '')
        ->set('platform', '')
        ->set('script_type', '')
        ->set('script_content', '')
        ->call('save')
        ->assertHasErrors(['name', 'category', 'platform', 'script_type', 'script_content']);
});

it('always sets is_system to false', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('name', 'User Script')
        ->set('category', ScriptCategory::Info->value)
        ->set('platform', ScriptPlatform::All->value)
        ->set('script_type', ScriptType::Bash->value)
        ->set('script_content', 'echo hello')
        ->call('save')
        ->assertHasNoErrors();

    expect(Script::where('name', 'User Script')->first()->is_system)->toBeFalse();
});

it('rejects unknown category, platform and script type values', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('name', 'Bad Script')
        ->set('category', 'mischief')
        ->set('platform', 'amiga')
        ->set('script_type', 'cobol')
        ->set('script_content', 'echo hi')
        ->call('save')
        ->assertHasErrors(['category', 'platform', 'script_type']);

    expect(Script::count())->toBe(0);
});
