<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('serves the agent installer script with correct base url', function (): void {
    $response = $this->get('/agent/install.ps1');

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertSee(url('/'))
        ->assertSee('INSTALL NETDATA')
        ->assertSee('INSTALL RMM TRAY AGENT')
        ->assertSee('benjameshughes/rmm');
});

it('shows agent page with download link for authenticated users', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/devices/agent')
        ->assertSuccessful()
        ->assertSee('Agent Installer')
        ->assertSee(route('agent.download'));
});
