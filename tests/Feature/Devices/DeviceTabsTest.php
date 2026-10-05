<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\DevicePowerState;
use App\Enums\DeviceTab;
use App\Livewire\Devices\Header;
use App\Livewire\Devices\Overview;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceMetric;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->create(['hostname' => 'TABBED-PC', 'last_seen' => now()]);
});

/**
 * The tab links the shell renders, keyed by their href, with whether each is marked current.
 *
 * @return array<string, bool>
 */
function deviceTabLinks(string $html): array
{
    preg_match_all('/<a\b[^>]*data-flux-navbar-items[^>]*>/', $html, $links);

    return collect($links[0])
        ->mapWithKeys(fn (string $link): array => [
            html_entity_decode(str($link)->match('/href="([^"]+)"/')->toString()) => str_contains($link, 'data-current="data-current"'),
        ])
        ->all();
}

it('serves every tab at its own bookmarkable route inside the shell', function (DeviceTab $tab): void {
    $this->actingAs($this->user)
        ->get(route($tab->routeName(), $this->device))
        ->assertSuccessful()
        ->assertSee('TABBED-PC')
        ->assertSee('Run Command')
        ->assertSee("TABBED-PC · {$tab->label()}", false)
        ->assertSeeInOrder(collect(DeviceTab::cases())->map->label()->all());
})->with(DeviceTab::cases());

it('keeps the overview at the devices.show route everything links to', function (): void {
    expect(route('devices.show', $this->device))->toEndWith("/devices/{$this->device->id}")
        ->and(route('devices.metrics', $this->device))->toEndWith("/devices/{$this->device->id}/metrics")
        ->and(route('devices.commands', $this->device))->toEndWith("/devices/{$this->device->id}/commands")
        ->and(route('devices.apps', $this->device))->toEndWith("/devices/{$this->device->id}/apps")
        ->and(route('devices.system', $this->device))->toEndWith("/devices/{$this->device->id}/system")
        ->and(route('devices.details', $this->device))->toEndWith("/devices/{$this->device->id}/details");
});

it('refuses every tab when viewing the device is denied', function (DeviceTab $tab): void {
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'view' ? false : null);

    $this->actingAs($this->user)
        ->get(route($tab->routeName(), $this->device))
        ->assertForbidden();
})->with(DeviceTab::cases());

it('sends guests to log in', function (): void {
    $this->get(route('devices.metrics', $this->device))->assertRedirect(route('login'));
});

it('marks only the current tab in the tab nav', function (DeviceTab $tab): void {
    $html = $this->actingAs($this->user)->get(route($tab->routeName(), $this->device))->getContent();

    $current = collect(deviceTabLinks($html))->filter()->keys()->all();

    expect($current)->toBe([route($tab->routeName(), $this->device)]);
})->with(DeviceTab::cases());

it('keeps Devices highlighted in the sidebar on every tab', function (DeviceTab $tab): void {
    $this->actingAs($this->user)
        ->get(route($tab->routeName(), $this->device))
        ->assertSeeHtml('href="'.route('devices.index').'" data-current');
})->with(DeviceTab::cases());

it('links back to the device list from the breadcrumb', function (): void {
    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('href="'.route('devices.index').'"')
        ->assertSee('Devices');
});

it('shows the status prominently with the model label and colour', function (array $attributes, string $label, string $color): void {
    $device = Device::factory()->active()->create($attributes);

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->assertSee($label)
        ->assertViewHas('statusLabel', $label)
        ->assertViewHas('statusColor', $color);
})->with([
    'online' => [fn (): array => ['last_seen' => now()], 'Online', 'green'],
    'offline' => [fn (): array => ['last_seen' => now()->subHour()], 'Offline', 'red'],
    'powering on' => [fn (): array => ['last_seen' => now(), 'power_state' => DevicePowerState::PoweringOn, 'power_state_changed_at' => now()], 'Powering on', 'sky'],
    'powering off' => [fn (): array => ['last_seen' => now(), 'power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()->setTime(17, 30)], 'Powering off since 17:30', 'amber'],
]);

it('shows when the device was last seen, its IP, OS and agent', function (): void {
    $device = Device::factory()->active()->create(['last_seen' => now()->subMinutes(3), 'last_ip' => '10.0.30.44', 'os_name' => 'Windows 11 Pro', 'agent_version' => '0.6.5']);

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->assertSee('Seen 3 minutes ago')
        ->assertSee('10.0.30.44')
        ->assertSee('Windows 11 Pro')
        ->assertSee('Agent 0.6.5');
});

it('says a device that never checked in was never seen', function (): void {
    $device = Device::factory()->active()->create(['last_seen' => null]);

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])->assertSee('Never seen');
});

describe('power menu', function (): void {
    beforeEach(fn () => app(SyncSystemScripts::class)());

    it('queues the right system script from each power menu item', function (string $method, string $slug, ?string $confirm): void {
        $page = Livewire::actingAs($this->user)->test(Header::class, ['device' => $this->device])
            ->assertSeeHtml("wire:click=\"{$method}\"");

        $confirm === null
            ? $page->assertDontSeeHtml("wire:click=\"{$method}\" wire:confirm")
            : $page->assertSeeHtml("wire:click=\"{$method}\" wire:confirm=\"{$confirm}");

        $page->call($method)->assertDispatched('command-queued');

        expect(DeviceCommand::query()->sole())
            ->device_id->toBe($this->device->id)
            ->script_id->toBe(Script::findSystem($slug)->id)
            ->queued_by->toBe($this->user->id);
    })->with([
        'restart' => ['restart', 'restart', 'Are you sure you want to restart TABBED-PC?'],
        'power off' => ['powerOff', 'shutdown', 'Are you sure you want to power off TABBED-PC?'],
        'log off' => ['logOff', 'log-off', 'Log the signed-in user off TABBED-PC?'],
        'check for updates' => ['checkForUpdates', 'windows-update', null],
        'update agent' => ['updateAgent', 'update-agent', null],
    ]);

    it('keeps power actions behind a Power menu instead of first in line', function (): void {
        Livewire::actingAs($this->user)->test(Header::class, ['device' => $this->device])
            ->assertSeeInOrder(['Run Command', 'Run Script', 'Power', 'Restart', 'Power Off', 'Log Off', 'Check for Updates', 'Update Agent']);
    });
});

describe('live updates', function (): void {
    it('refreshes the overview stats when the device reports', function (): void {
        DeviceMetric::factory()->create(['device_id' => $this->device->id, 'cpu' => 11.0]);

        $overview = Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
            ->assertSee('11.0%');

        $this->travel(1)->minute();
        DeviceMetric::factory()->create(['device_id' => $this->device->id, 'cpu' => 87.0]);

        $overview->dispatch("echo-private:devices.{$this->device->id},DeviceUpdated", ['deviceId' => $this->device->id, 'status' => 'active'])
            ->assertSee('87.0%')
            ->assertDontSee('11.0%');
    });

    it('refreshes the overview command list when this page queues one', function (): void {
        $overview = Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
            ->assertSee('No commands run on this device yet.');

        DeviceCommand::factory()->pending()->create(['device_id' => $this->device->id]);

        $overview->dispatch('command-queued')->assertDontSee('No commands run on this device yet.');
    });

    it('shows open alerts for this device as they trigger and ignores other devices', function (): void {
        $overview = Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
            ->assertDontSee('open alert');

        $elsewhere = Alert::factory()->triggered()->create();
        $overview->dispatch('echo-private:devices,AlertChanged', ['alertId' => $elsewhere->id, 'deviceId' => $elsewhere->device_id, 'status' => 'triggered'])
            ->assertDontSee('open alert');

        $alert = Alert::factory()->triggered()->create(['device_id' => $this->device->id]);
        $overview->dispatch('echo-private:devices,AlertChanged', ['alertId' => $alert->id, 'deviceId' => $this->device->id, 'status' => 'triggered'])
            ->assertSee('1 open alert')
            ->assertSee($alert->conditionLabel());
    });
});
