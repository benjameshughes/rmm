<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('serves the agent installer script with correct base url', function (): void {
    $response = $this->get('/agent/install.ps1');

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertSee('$ServerUrl    = "'.url('/').'"', false)
        ->assertDontSee('{BASE_URL}')
        ->assertSee('benjameshughes/rmm')
        ->assertSee('$ServiceName  = "BenJHRMM"', false)
        ->assertSee('& $agentExe --url $ServerUrl', false)
        ->assertSee('netdata-x64.msi');
});

it('never queries Win32_Product, adds antivirus exclusions or matches other RMM products', function (): void {
    $script = $this->get('/agent/install.ps1')->assertSuccessful()->getContent();

    expect($script)
        ->not->toContain('Get-WmiObject')
        ->not->toContain('Win32_Product |')
        ->not->toContain('Add-MpPreference')
        ->not->toContain('*RMM*')
        ->not->toContain('try {');
});

it('shows agent page with download link for authenticated users', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/devices/agent')
        ->assertSuccessful()
        ->assertSee('Agent Installer')
        ->assertSee(route('agent.download'));
});
