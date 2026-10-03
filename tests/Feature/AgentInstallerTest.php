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

it('serves the Linux installer script with the server url', function (): void {
    $response = $this->get('/agent/install.sh');

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="agent-install.sh"')
        ->assertSee('SERVER_URL="'.url('/').'"', false)
        ->assertDontSee('{BASE_URL}');
});

it('verifies the Linux agent checksum before installing it as root on x86_64 only', function (): void {
    $script = $this->get('/agent/install.sh')->assertSuccessful()->getContent();

    expect($script)
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('set -euo pipefail')
        ->toContain('id -u')
        ->toContain('"$ARCH" = "x86_64"')
        ->toContain('benjameshughes/rmm')
        ->toContain('releases/latest/download')
        ->toContain('ASSET="rmm-linux-x86_64"')
        ->toContain('"${ASSET}.sha256"')
        ->toContain('sha256sum -c "${ASSET}.sha256"')
        ->toContain('INSTALL_PATH="/usr/local/bin/rmm"')
        ->toContain('-m 0755')
        ->toContain('"${INSTALL_PATH}" --url "${SERVER_URL}" install')
        ->toContain('approve');

    expect(strpos($script, 'sha256sum -c'))->toBeLessThan(strpos($script, 'install -o root'));
});

it('shows the Linux monitor-only one-liner on the agent page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/devices/agent')
        ->assertSuccessful()
        ->assertSee('Linux (monitor only)')
        ->assertSee('curl -fsSL '.route('agent.download.linux').' | sudo bash')
        ->assertSee('never runs commands');
});
