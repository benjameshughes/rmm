<?php

declare(strict_types=1);

use App\Events\SystemInventorySynced;
use App\Livewire\Devices\Security;
use App\Models\Device;
use App\Models\DeviceInventory;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->monitorOnly()->withApiKey('LINUX-KEY')->create(['hostname' => 'web1']);
});

/**
 * A well-kept box's security report, overridable per section.
 *
 * @return array<string, mixed>
 */
function linuxSecurityReport(array $overrides = []): array
{
    return array_replace([
        'collected_at' => '2026-10-27T09:00:00Z',
        'updates' => ['pending' => 0, 'security' => 0, 'reboot_required' => false, 'reboot_packages' => []],
        'unattended_upgrades' => ['installed' => true, 'enabled' => true, 'last_run_at' => now()->subHours(5)->toIso8601String()],
        'ssh' => ['source' => 'sshd -T', 'permit_root_login' => 'no', 'password_authentication' => false, 'kbd_interactive_authentication' => false],
        'authorized_keys' => [
            ['user' => 'ben', 'key_type' => 'ssh-ed25519', 'comment' => 'ben@laptop', 'fingerprint' => 'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU'],
        ],
        'firewall' => ['nftables_active' => true, 'nftables_table_count' => 1, 'nftables_rule_count' => 12, 'ufw_active' => null],
        'failed_logins' => ['source' => 'journal', 'window_hours' => 24, 'count' => 3, 'top_sources' => [['ip' => '203.0.113.9', 'count' => 3]]],
        'listening_ports' => [
            ['protocol' => 'tcp', 'address' => '0.0.0.0', 'port' => 22, 'process_name' => 'sshd', 'process_id' => 812],
            ['protocol' => 'udp', 'address' => '127.0.0.53%lo', 'port' => 53, 'process_name' => 'systemd-resolve', 'process_id' => 500],
        ],
    ], $overrides);
}

function postSecurityMetrics(array $report): TestResponse
{
    return test()->withHeaders(['X-Device-Key' => 'LINUX-KEY'])->postJson('/api/metrics', [
        'monitor_only' => true,
        'agent_version' => '0.10.0',
        'cpu' => ['usage_percent' => 12],
        ...$report,
    ]);
}

function securityReported(Device $device, array $report): void
{
    DeviceInventory::query()->create(['device_id' => $device->id, 'collected_at' => now()->subHour(), 'data' => ['linux_security' => $report]]);
    $device->forceFill(['system_inventoried_at' => now()->subHour()])->save();
}

it('stores the security report riding along with the metrics as an inventory snapshot', function (): void {
    Event::fake([SystemInventorySynced::class]);

    postSecurityMetrics(['linux_security' => linuxSecurityReport()])->assertSuccessful();

    $inventory = DeviceInventory::query()->sole();

    expect($inventory->device_id)->toBe($this->device->id)
        ->and($inventory->data['linux_security']['ssh']['permit_root_login'])->toBe('no')
        ->and($inventory->data['linux_security']['listening_ports'])->toHaveCount(2)
        ->and($this->device->fresh()->system_inventoried_at)->not->toBeNull()
        ->and(DeviceMetric::query()->count())->toBe(1);
    Event::assertDispatched(SystemInventorySynced::class, fn (SystemInventorySynced $event): bool => $event->deviceId === $this->device->id);
});

it('stores nothing extra when a report carries no security section', function (): void {
    postSecurityMetrics([])->assertSuccessful();

    expect(DeviceInventory::query()->count())->toBe(0);
});

it('ignores a security report from a Windows device', function (): void {
    $windows = Device::factory()->active()->windows()->withApiKey('WIN-KEY')->create();

    $this->withHeaders(['X-Device-Key' => 'WIN-KEY'])
        ->postJson('/api/metrics', ['cpu' => ['usage_percent' => 5], 'linux_security' => linuxSecurityReport()])
        ->assertSuccessful();

    expect($windows->inventories()->count())->toBe(0);
});

it('rejects a security report that is not well formed', function (array $report, string $error): void {
    postSecurityMetrics(['linux_security' => $report])->assertUnprocessable()->assertJsonValidationErrors($error);

    expect(DeviceInventory::query()->count())->toBe(0);
})->with([
    'a key without its fingerprint' => [['authorized_keys' => [['user' => 'ben', 'key_type' => 'ssh-ed25519']]], 'linux_security.authorized_keys.0.fingerprint'],
    'a source that is not an address' => [['failed_logins' => ['count' => 1, 'top_sources' => [['ip' => 'not-an-ip', 'count' => 1]]]], 'linux_security.failed_logins.top_sources.0.ip'],
    'a port out of range' => [['listening_ports' => [['protocol' => 'tcp', 'address' => '*', 'port' => 70000]]], 'linux_security.listening_ports.0.port'],
]);

it('shows a well-kept box all green on the Security tab', function (): void {
    securityReported($this->device, linuxSecurityReport());

    Livewire::actingAs($this->user)->test(Security::class, ['device' => $this->device])
        ->assertSeeHtml('data-linux-posture="green"')
        ->assertSee('Security report · Collected 1 hour ago.')
        ->assertSeeInOrder(['Pending updates', 'Up to date'])
        ->assertSeeInOrder(['Unattended upgrades', 'On · last ran 5 hours ago'])
        ->assertSeeInOrder(['Root login', 'Off'])
        ->assertSeeInOrder(['Password login', 'Off'])
        ->assertSeeInOrder(['nftables', 'Active · 12 rules in 1 table'])
        ->assertSeeInOrder(['Failed SSH logins', '3 in 24 hours · journal', '203.0.113.9'])
        ->assertSeeInOrder(['Authorized SSH keys (1)', 'ben@laptop', 'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU'])
        ->assertSeeInOrder(['Listening ports (2)', 'tcp', '22', '0.0.0.0', 'sshd', '812'])
        ->assertDontSee('only collected from Windows devices');
});

it('flags an exposed box amber and red', function (): void {
    config(['inventory.linux_failed_logins_critical' => 500]);

    securityReported($this->device, linuxSecurityReport([
        'updates' => ['pending' => 14, 'security' => 3, 'reboot_required' => true, 'reboot_packages' => ['linux-image-amd64']],
        'unattended_upgrades' => ['installed' => true, 'enabled' => false, 'last_run_at' => null],
        'ssh' => ['source' => 'sshd_config', 'permit_root_login' => 'yes', 'password_authentication' => true, 'kbd_interactive_authentication' => true],
        'firewall' => ['nftables_active' => false, 'nftables_table_count' => 0, 'nftables_rule_count' => 0, 'ufw_active' => false],
        'failed_logins' => ['source' => 'auth.log', 'window_hours' => 24, 'count' => 1200, 'top_sources' => []],
    ]));

    $security = $this->device->fresh()->latestInventory->snapshot()->linuxSecurity();

    expect($security->checks()->mapWithKeys(fn ($check): array => [$check->label => $check->color])->all())->toBe([
        'Pending updates' => 'red',
        'Reboot' => 'amber',
        'Unattended upgrades' => 'amber',
        'Root login' => 'red',
        'Password login' => 'red',
        'Keyboard-interactive login' => 'amber',
        'nftables' => 'red',
        'ufw' => 'zinc',
        'Failed SSH logins' => 'red',
    ])->and($security->postureCheck()->value)->toBe('8 to check');

    Livewire::actingAs($this->user)->test(Security::class, ['device' => $this->device])
        ->assertSeeHtml('data-linux-posture="red"')
        ->assertSeeInOrder(['Pending updates', '3 security · 14 in all'])
        ->assertSeeInOrder(['Reboot', 'Required · linux-image-amd64'])
        ->assertSeeInOrder(['Root login', 'Allowed, password too'])
        ->assertSee('Effective settings from sshd_config.')
        ->assertSeeInOrder(['nftables', 'Inactive'])
        ->assertSee('1,200 in 24 hours · auth.log');
});

it('does not hold an inactive nftables against a box ufw is filtering', function (): void {
    securityReported($this->device, linuxSecurityReport([
        'firewall' => ['nftables_active' => false, 'nftables_table_count' => 0, 'nftables_rule_count' => 0, 'ufw_active' => true],
    ]));

    $firewall = $this->device->fresh()->latestInventory->snapshot()->linuxSecurity()->firewall();

    expect($firewall->pluck('color', 'label')->all())->toBe(['nftables' => 'zinc', 'ufw' => 'green']);
});

it('shows readings the agent could not take as unknown, not as zero', function (): void {
    securityReported($this->device, [
        'collected_at' => '2026-10-27T09:00:00Z',
        'updates' => ['pending' => null, 'security' => null, 'reboot_required' => false, 'reboot_packages' => []],
        'unattended_upgrades' => ['installed' => true, 'enabled' => null, 'last_run_at' => null],
        'ssh' => null,
        'authorized_keys' => null,
        'firewall' => ['nftables_active' => null, 'nftables_table_count' => null, 'nftables_rule_count' => null, 'ufw_active' => null],
        'failed_logins' => null,
        'listening_ports' => null,
    ]);

    Livewire::actingAs($this->user)->test(Security::class, ['device' => $this->device])
        ->assertSeeHtml('data-linux-posture="green"')
        ->assertSeeInOrder(['Pending updates', 'Unknown'])
        ->assertSeeInOrder(['Unattended upgrades', 'Installed, setting unknown'])
        ->assertSeeInOrder(['SSH server', 'Not found'])
        ->assertSeeInOrder(['nftables', 'Unknown'])
        ->assertSeeInOrder(['Failed SSH logins', 'Not readable'])
        ->assertDontSee('Authorized SSH keys')
        ->assertSee('Listening ports (0)');
});

it('waits for the first report on a Linux box', function (): void {
    Livewire::actingAs($this->user)->test(Security::class, ['device' => $this->device])
        ->assertSeeHtml('data-linux-security-empty')
        ->assertSee('No security report yet');
});
