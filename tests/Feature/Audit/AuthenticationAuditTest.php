<?php

declare(strict_types=1);

use App\Actions\Device\WakeDevice;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->withoutTwoFactor()->create(['email' => 'ben@example.com']);
});

function signIn(string $email, string $password, string $userAgent = 'AuditBrowser/1.0'): Illuminate\Testing\TestResponse
{
    return test()->withHeader('User-Agent', $userAgent)->post(route('login.store'), [
        'email' => $email,
        'password' => $password,
    ]);
}

it('records a first sign-in as one from a new device', function (): void {
    signIn('ben@example.com', 'password')->assertSessionHasNoErrors();

    $login = AuditLog::query()->where('action', AuditAction::LoginFromNewDevice)->sole();

    expect($login->user_id)->toBe($this->user->id)
        ->and($login->subject_id)->toBe($this->user->id)
        ->and($login->ip)->toBe('127.0.0.1')
        ->and($login->user_agent)->toBe('AuditBrowser/1.0');
});

it('records a repeat sign-in from the same IP and browser as a plain sign-in', function (): void {
    signIn('ben@example.com', 'password');
    $this->post(route('logout'));
    signIn('ben@example.com', 'password');

    expect(AuditLog::query()->where('action', AuditAction::LoginFromNewDevice)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::Login)->count())->toBe(1);
});

it('treats a new browser as a new device', function (): void {
    signIn('ben@example.com', 'password');
    $this->post(route('logout'));
    signIn('ben@example.com', 'password', 'SomeOtherBrowser/2.0');

    expect(AuditLog::query()->where('action', AuditAction::LoginFromNewDevice)->count())->toBe(2);
});

it('records a failed sign-in with the email only', function (): void {
    signIn('ben@example.com', 'wrong-password-123')->assertSessionHasErrors('email');

    $failed = AuditLog::query()->where('action', AuditAction::LoginFailed)->sole();

    expect($failed->user_id)->toBeNull()
        ->and($failed->actorName())->toBe('Unknown')
        ->and($failed->properties)->toBe(['label' => 'ben@example.com', 'email' => 'ben@example.com'])
        ->and(json_encode($failed->getAttributes()))->not->toContain('wrong-password-123');
});

it('records a failed sign-in for an email nobody has', function (): void {
    signIn('stranger@example.com', 'guessing-123');

    expect(AuditLog::query()->where('action', AuditAction::LoginFailed)->sole())
        ->subject_id->toBeNull()
        ->properties->toMatchArray(['email' => 'stranger@example.com']);
});

it('records signing out', function (): void {
    $this->actingAs($this->user)->post(route('logout'));

    expect(AuditLog::query()->where('action', AuditAction::Logout)->sole()->user_id)->toBe($this->user->id);
});

it('records a password reset', function (): void {
    $token = Password::createToken($this->user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $this->user->email,
        'password' => 'a-whole-new-password',
        'password_confirmation' => 'a-whole-new-password',
    ])->assertSessionHasNoErrors();

    $reset = AuditLog::query()->where('action', AuditAction::PasswordReset)->sole();

    expect($reset->user_id)->toBe($this->user->id)
        ->and(json_encode(AuditLog::pluck('properties')))->not->toContain('a-whole-new-password');
});

it('records two-factor being enabled, confirmed and disabled', function (): void {
    $this->actingAs($this->user);

    app(EnableTwoFactorAuthentication::class)($this->user);
    TwoFactorAuthenticationConfirmed::dispatch($this->user);
    app(DisableTwoFactorAuthentication::class)($this->user);

    expect(AuditLog::query()->whereIn('action', [AuditAction::TwoFactorEnabled, AuditAction::TwoFactorConfirmed, AuditAction::TwoFactorDisabled])->orderBy('id')->pluck('action')->all())
        ->toBe([AuditAction::TwoFactorEnabled, AuditAction::TwoFactorConfirmed, AuditAction::TwoFactorDisabled])
        ->and(AuditLog::query()->where('action', AuditAction::UserUpdated)->exists())->toBeFalse()
        ->and(json_encode(AuditLog::pluck('properties')))->not->toContain('two_factor');
});

it('records a wake request', function (): void {
    listenForWakePackets();
    $device = Device::factory()->active()->create(['hostname' => 'SLEEPY-PC', 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

    $this->actingAs($this->user);
    app(WakeDevice::class)($device);

    $wake = AuditLog::query()->where('action', AuditAction::DeviceWakeRequested)->sole();

    expect($wake->user_id)->toBe($this->user->id)
        ->and($wake->subject_id)->toBe($device->id)
        ->and($wake->properties)->toMatchArray(['label' => 'SLEEPY-PC', 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);
});

it('records a scheduled wake with no actor', function (): void {
    listenForWakePackets();
    $device = Device::factory()->active()->create(['mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

    app(WakeDevice::class)($device);

    expect(AuditLog::query()->where('action', AuditAction::DeviceWakeRequested)->sole()->user_id)->toBeNull();
});
