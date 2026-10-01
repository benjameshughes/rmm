<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

pest()->use(RefreshDatabase::class);

it('creates a verified user from options', function (): void {
    $this->artisan('users:create', [
        '--name' => 'Ben Admin',
        '--email' => 'admin@example.com',
        '--password' => 'super-secret-password',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'admin@example.com')->sole();

    expect($user->name)->toBe('Ben Admin');
    expect($user->email_verified_at)->not->toBeNull();
    expect(Hash::check('super-secret-password', $user->password))->toBeTrue();
});

it('prompts for missing details', function (): void {
    $this->artisan('users:create')
        ->expectsQuestion('Name', 'Prompted User')
        ->expectsQuestion('Email', 'prompted@example.com')
        ->expectsQuestion('Password', 'super-secret-password')
        ->assertSuccessful();

    $user = User::query()->where('email', 'prompted@example.com')->sole();

    expect($user->name)->toBe('Prompted User');
    expect($user->email_verified_at)->not->toBeNull();
});

it('only prompts for options that were not provided', function (): void {
    $this->artisan('users:create', ['--name' => 'Partial User', '--email' => 'partial@example.com'])
        ->expectsQuestion('Password', 'super-secret-password')
        ->assertSuccessful();

    expect(User::query()->where('email', 'partial@example.com')->exists())->toBeTrue();
});

it('fails when the email is already taken', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('users:create', [
        '--name' => 'Duplicate',
        '--email' => 'taken@example.com',
        '--password' => 'super-secret-password',
    ])
        ->expectsOutputToContain('The email has already been taken.')
        ->assertFailed();

    expect(User::query()->where('email', 'taken@example.com')->count())->toBe(1);
});

it('fails when the password is too short', function (): void {
    $this->artisan('users:create', [
        '--name' => 'Weak',
        '--email' => 'weak@example.com',
        '--password' => 'short',
    ])
        ->expectsOutputToContain('The password field must be at least 8 characters.')
        ->assertFailed();

    expect(User::query()->where('email', 'weak@example.com')->exists())->toBeFalse();
});

it('fails when the email is invalid', function (string $email): void {
    $this->artisan('users:create', [
        '--name' => 'Invalid',
        '--email' => $email,
        '--password' => 'super-secret-password',
    ])->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'missing at sign' => 'not-an-email',
    'too long' => str_repeat('a', 250).'@example.com',
]);
