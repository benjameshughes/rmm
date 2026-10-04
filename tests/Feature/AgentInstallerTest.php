<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

pest()->use(RefreshDatabase::class);

it('serves the agent installer script with correct base url and leaves Netdata to approval', function (): void {
    $response = $this->get('/agent/install.ps1');

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertSee('$ServerUrl    = "'.url('/').'"', false)
        ->assertDontSee('{BASE_URL}')
        ->assertSee('benjameshughes/rmm')
        ->assertSee('$ServiceName  = "BenJHRMM"', false)
        ->assertSee('& $agentExe --url $ServerUrl', false)
        ->assertDontSee('netdata-x64.msi');
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

it('installs the pinned Netdata on localhost before the Linux agent, never claiming it', function (): void {
    $script = $this->get('/agent/install.sh')->assertSuccessful()->getContent();

    expect($script)
        ->not->toContain('{NETDATA_INSTALLER}')
        ->toContain('NETDATA_VERSION="2.12.0"')
        ->toContain('https://get.netdata.cloud/kickstart.sh')
        ->toContain('--non-interactive')
        ->toContain('--stable-channel')
        ->toContain('--native-only')
        ->toContain('--install-version "${NETDATA_VERSION}"')
        ->toContain('--no-updates')
        ->toContain('--disable-telemetry')
        ->toContain('apt-mark hold netdata')
        ->toContain('bind to = 127.0.0.1')
        ->toContain('.opt-out-from-anonymous-statistics')
        ->not->toContain('--claim');

    expect(strpos($script, 'kickstart.sh'))->toBeLessThan(strpos($script, '"${INSTALL_PATH}" --url "${SERVER_URL}" install'));
});

it('skips the Netdata install when the pinned version is there and restarts it only when its config changes', function (): void {
    $script = File::get(config('scripts.linux_netdata_installer'));

    expect($script)
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('set -euo pipefail')
        ->toContain('"ii ${NETDATA_VERSION}"*)')
        ->toContain('already installed')
        ->toContain('config_changed=1')
        ->toContain('if [ "$config_changed" -eq 1 ]; then')
        ->toContain('systemctl restart netdata');
});

it('restarts the Linux agent after installing so an upgrade runs the new binary', function (): void {
    $script = $this->get('/agent/install.sh')->assertSuccessful()->getContent();

    expect(strpos($script, 'systemctl restart benjh-rmm'))->toBeGreaterThan(strpos($script, '--url "${SERVER_URL}" install'));
});
