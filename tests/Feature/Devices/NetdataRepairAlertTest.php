<?php

declare(strict_types=1);

use App\Enums\AlertMetric;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Events\CommandUpdated;
use App\Events\MetricsReceived;
use App\Listeners\ResolveNetdataRepairAlertForMetrics;
use App\Listeners\SyncNetdataRepairAlertForCommand;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceMetric;
use App\Models\Script;
use App\Models\User;
use App\Notifications\AlertTriggered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

pest()->use(RefreshDatabase::class);

const FAILED_REPAIR_OUTPUT = "Netdata service was StopPending and its API was not answering; restarting it\n"
    ."Netdata was StopPending after 20 seconds; killing 7 Netdata processes: netdata.exe, go.d.plugin.exe, sh.exe\n"
    ."ATTENTION: Netdata is still not reporting CPU after a forced restart. Rebooting the PC is the next step\n";

beforeEach(function (): void {
    $this->script = Script::factory()->system()->create(['slug' => 'repair-netdata']);
    $this->device = Device::factory()->windows()->active()->create(['hostname' => 'DESKTOP-D3TOBC1']);
});

function runRepair(Device $device, Script $script): DeviceCommand
{
    return DeviceCommand::factory()->pending()->create(['device_id' => $device->id, 'script_id' => $script->id]);
}

function reportCpu(Device $device, ?float $cpu): void
{
    MetricsReceived::dispatch($device, DeviceMetric::factory()->create(['device_id' => $device->id, 'cpu' => $cpu]));
}

it('listens for finished commands and metrics', function (): void {
    Event::fake();

    Event::assertListening(CommandUpdated::class, SyncNetdataRepairAlertForCommand::class);
    Event::assertListening(MetricsReceived::class, ResolveNetdataRepairAlertForMetrics::class);
});

it('raises one alert from the built-in rule when the repair fails, with its ATTENTION line', function (): void {
    runRepair($this->device, $this->script)->markAsCompleted(FAILED_REPAIR_OUTPUT, 1);

    $alert = Alert::query()->sole();
    expect($alert->metric)->toBe(AlertMetric::NetdataRepairFailed)
        ->and($alert->status)->toBe(AlertStatus::Triggered)
        ->and($alert->severity)->toBe(AlertSeverity::Warning)
        ->and($alert->device_id)->toBe($this->device->id)
        ->and($alert->message)->toBe('DESKTOP-D3TOBC1: Netdata repair failed, metrics are blank. Consider rebooting the PC. ATTENTION: Netdata is still not reporting CPU after a forced restart. Rebooting the PC is the next step')
        ->and($alert->alertRule->is(AlertRule::netdataRepairFailed()))->toBeTrue()
        ->and(AlertRule::netdataRepairFailed()->name)->toBe('Netdata repair failed');
});

it('raises it when the repair times out, without output', function (): void {
    runRepair($this->device, $this->script)->markAsTimedOut();

    expect(Alert::query()->sole()->message)->toBe('DESKTOP-D3TOBC1: Netdata repair failed, metrics are blank. Consider rebooting the PC.');
});

it('cuts a long ATTENTION line so the message fits', function (): void {
    runRepair($this->device, $this->script)->markAsCompleted('ATTENTION: '.str_repeat('x', 500), 1);

    expect(mb_strlen(Alert::query()->sole()->message))->toBeLessThan(255);
});

it('notifies users like any other alert', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    runRepair($this->device, $this->script)->markAsCompleted(FAILED_REPAIR_OUTPUT, 1);

    Notification::assertSentTo($user, AlertTriggered::class);
});

it('raises nothing when the repair works', function (): void {
    runRepair($this->device, $this->script)->markAsCompleted('OK: Netdata is reporting CPU again', 0);

    expect(Alert::query()->exists())->toBeFalse();
});

it('raises nothing while the repair is still pending, running or was cancelled', function (): void {
    $repair = runRepair($this->device, $this->script);
    $repair->markAsSent();
    $repair->markAsRunning();
    runRepair($this->device, $this->script)->cancel();

    expect(Alert::query()->exists())->toBeFalse();
});

it('keeps one open alert per device when a later repair fails again', function (): void {
    runRepair($this->device, $this->script)->markAsCompleted(FAILED_REPAIR_OUTPUT, 1);
    runRepair($this->device, $this->script)->markAsTimedOut();

    $alert = Alert::query()->sole();
    expect($alert->status)->toBe(AlertStatus::Triggered)
        ->and($alert->message)->toBe('DESKTOP-D3TOBC1: Netdata repair failed, metrics are blank. Consider rebooting the PC.');
});

it('keeps each device apart', function (): void {
    $other = Device::factory()->windows()->active()->create();

    runRepair($this->device, $this->script)->markAsCompleted(FAILED_REPAIR_OUTPUT, 1);
    runRepair($other, $this->script)->markAsCompleted(FAILED_REPAIR_OUTPUT, 1);

    expect(Alert::query()->count())->toBe(2);
});

it('resolves it on the next report with CPU, not on a blank one', function (): void {
    runRepair($this->device, $this->script)->markAsCompleted(FAILED_REPAIR_OUTPUT, 1);

    reportCpu($this->device, null);

    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Triggered);

    reportCpu($this->device, 14.0);

    $alert = Alert::query()->sole();
    expect($alert->status)->toBe(AlertStatus::Resolved)
        ->and($alert->resolved_at)->not->toBeNull();
});

it('resolves it when a later repair works', function (): void {
    runRepair($this->device, $this->script)->markAsCompleted(FAILED_REPAIR_OUTPUT, 1);
    runRepair($this->device, $this->script)->markAsCompleted('Nothing to do: Netdata is reporting CPU', 0);

    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Resolved);
});

it('raises a fresh alert when the repair fails again after one was resolved', function (): void {
    runRepair($this->device, $this->script)->markAsCompleted(FAILED_REPAIR_OUTPUT, 1);
    reportCpu($this->device, 9.0);
    runRepair($this->device, $this->script)->markAsCompleted(FAILED_REPAIR_OUTPUT, 1);

    expect(Alert::query()->where('status', AlertStatus::Triggered)->count())->toBe(1)
        ->and(Alert::query()->where('status', AlertStatus::Resolved)->count())->toBe(1);
});

it('raises nothing once the rule is switched off', function (): void {
    AlertRule::netdataRepairFailed()->update(['is_active' => false]);

    runRepair($this->device, $this->script)->markAsCompleted(FAILED_REPAIR_OUTPUT, 1);

    expect(Alert::query()->exists())->toBeFalse();
});

it('ignores other scripts failing', function (): void {
    runRepair($this->device, Script::factory()->system()->create(['slug' => 'install-netdata']))->markAsCompleted('ATTENTION: no', 1);
    runRepair($this->device, Script::factory()->create(['slug' => 'my-repair']))->markAsCompleted('ATTENTION: no', 1);

    expect(Alert::query()->exists())->toBeFalse();
});

it('raises at most one failed repair a day however long the PC stays blank', function (): void {
    collect(range(1, 10))->each(fn (): null => reportCpu($this->device, null));
    DeviceCommand::query()->sole()->markAsCompleted(FAILED_REPAIR_OUTPUT, 1);
    collect(range(1, 10))->each(fn (): null => reportCpu($this->device, null));

    expect(DeviceCommand::query()->count())->toBe(1)
        ->and(Alert::query()->count())->toBe(1);

    $this->travel(25)->hours();
    reportCpu($this->device, null);
    DeviceCommand::query()->latest('id')->first()->markAsCompleted(FAILED_REPAIR_OUTPUT, 1);

    expect(DeviceCommand::query()->count())->toBe(2)
        ->and(Alert::query()->sole()->status)->toBe(AlertStatus::Triggered);
});
