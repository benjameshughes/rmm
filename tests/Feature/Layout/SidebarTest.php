<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('brands the app as BenJH RMM', function (): void {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('BenJH RMM')
        ->assertDontSee('Laravel Starter Kit');
});

it('groups the navigation into fleet, automation, monitoring and setup', function (): void {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertSeeInOrder([
            'Fleet', 'Dashboard', 'Devices', 'Pending',
            'Automation', 'Scripts', 'Schedules',
            'Monitoring', 'Alerts', 'Audit Log',
            'Setup', 'Groups', 'Agent',
        ]);
});

it('drops the starter kit repository and documentation links', function (): void {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertDontSee('Repository')
        ->assertDontSee('Documentation')
        ->assertDontSee('livewire-starter-kit')
        ->assertDontSee('laravel.com/docs/starter-kits');
});

it('keeps both alert bells and the user menu', function (): void {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertSeeLivewire('alert-bell')
        ->assertSee($this->user->email)
        ->assertSee('Log Out')
        ->assertSee(route('profile.edit'), false);
});

it('still hides the audit log from users who may not see it', function (): void {
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'viewAny' ? false : null);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertDontSee('Audit Log');
});
