<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

pest()->use(RefreshDatabase::class);

it('does not register the registration routes', function (): void {
    expect(Route::has('register'))->toBeFalse();
    expect(Route::has('register.store'))->toBeFalse();
});

it('returns not found for the registration screen', function (): void {
    $this->get('/register')->assertNotFound();
});

it('does not allow anyone to register', function (): void {
    $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

it('does not link to registration from the login screen', function (): void {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertDontSee('Sign up');
});
