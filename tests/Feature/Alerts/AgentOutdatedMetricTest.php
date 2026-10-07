<?php

declare(strict_types=1);

use App\Actions\Alert\EvaluateAlertRules;
use App\Enums\AlertMetric;
use App\Livewire\AlertRules\Index as AlertRulesIndex;
use App\Livewire\Alerts\Index as AlertsIndex;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('describes every metric', function (AlertMetric $metric): void {
    expect($metric->label())->not->toBeEmpty()
        ->and($metric->unit())->toBeString()
        ->and($metric->isThresholdBased())->toBeBool();
})->with(AlertMetric::cases());

it('only offers threshold based metrics for user rules', function (): void {
    expect(AlertMetric::thresholdBased())->not->toContain(AlertMetric::AgentOutdated)
        ->and(AlertMetric::thresholdBased())->not->toContain(AlertMetric::ScriptFailed)
        ->and(AlertMetric::thresholdBased())->not->toContain(AlertMetric::VirtualPrinterDown)
        ->and(AlertMetric::thresholdBased())->toHaveCount(count(AlertMetric::cases()) - 3);
});

it('skips the outdated-agent rule when evaluating metrics', function (): void {
    AlertRule::agentOutdated();
    $device = Device::factory()->active()->create(['agent_version' => '0.0.1']);
    $metric = DeviceMetric::factory()->create(['device_id' => $device->id]);

    app(EvaluateAlertRules::class)($device, $metric);

    expect(Alert::query()->count())->toBe(0);
});

it('renders an outdated-agent alert with its message', function (): void {
    $alert = Alert::factory()->triggered()->create([
        'alert_rule_id' => AlertRule::agentOutdated()->id,
        'metric' => AlertMetric::AgentOutdated,
        'threshold' => 0,
        'current_value' => 1,
        'message' => 'PC-1 is on agent 0.5.0, latest is 0.5.1',
    ]);

    Livewire::actingAs(User::factory()->create())->test(AlertsIndex::class)
        ->assertSee('Agent Outdated')
        ->assertSee('PC-1 is on agent 0.5.0, latest is 0.5.1')
        ->assertDontSee('1 min');

    expect($alert->conditionLabel())->toBe('Agent Outdated');
});

it('renders threshold alerts with their unit', function (): void {
    $alert = Alert::factory()->create(['metric' => AlertMetric::Cpu, 'threshold' => 90, 'current_value' => 95.27]);

    expect($alert->valueLabel())->toBe('95.3%')
        ->and($alert->conditionLabel())->toBe('CPU Usage > 90%');
});

it('shows the built-in rule without edit or delete and keeps it out of the form', function (): void {
    AlertRule::agentOutdated();

    Livewire::actingAs(User::factory()->create())->test(AlertRulesIndex::class)
        ->assertSee('Agent outdated')
        ->assertSee('Built-in')
        ->assertDontSee('Edit')
        ->assertViewHas('metrics', AlertMetric::thresholdBased());
});

it('refuses to edit or delete the built-in rule', function (string $method): void {
    $rule = AlertRule::agentOutdated();

    Livewire::actingAs(User::factory()->create())->test(AlertRulesIndex::class)
        ->call($method, $rule->id)
        ->assertForbidden();

    expect($rule->fresh())->not->toBeNull();
})->with(['edit', 'delete']);

it('rejects creating a rule for the outdated-agent metric', function (): void {
    Livewire::actingAs(User::factory()->create())->test(AlertRulesIndex::class)
        ->set('name', 'Sneaky')
        ->set('metric', AlertMetric::AgentOutdated->value)
        ->call('create')
        ->assertHasErrors(['metric']);
});

it('can still be switched off', function (): void {
    $rule = AlertRule::agentOutdated();

    Livewire::actingAs(User::factory()->create())->test(AlertRulesIndex::class)
        ->call('toggleActive', $rule->id);

    expect($rule->fresh()->is_active)->toBeFalse();
});
