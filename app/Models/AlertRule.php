<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertMetric;
use App\Enums\AlertOperator;
use App\Enums\AlertSeverity;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AlertRule extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'name',
        'metric',
        'operator',
        'threshold',
        'duration_minutes',
        'severity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
            'metric' => AlertMetric::class,
            'operator' => AlertOperator::class,
            'severity' => AlertSeverity::class,
        ];
    }

    /**
     * The built-in rule behind outdated-agent alerts, created on first use so it
     * can be switched off under Alert Rules like any other rule.
     */
    public static function agentOutdated(): self
    {
        return self::query()->firstOrCreate(['metric' => AlertMetric::AgentOutdated], [
            'name' => config('agent.outdated_alert.rule_name'),
            'operator' => AlertOperator::GreaterThan,
            'threshold' => 0,
            'duration_minutes' => 0,
            'severity' => AlertSeverity::from(config('agent.outdated_alert.severity')),
            'is_active' => true,
        ]);
    }

    /**
     * The built-in rule behind failed scheduled script alerts, created on first
     * use so it can be switched off under Alert Rules like any other rule.
     */
    public static function scheduledScriptFailed(): self
    {
        return self::query()->firstOrCreate(['metric' => AlertMetric::ScriptFailed], [
            'name' => config('alerts.scheduled_script_failed.rule_name'),
            'operator' => AlertOperator::GreaterThan,
            'threshold' => 0,
            'duration_minutes' => 0,
            'severity' => AlertSeverity::from(config('alerts.scheduled_script_failed.severity')),
            'is_active' => true,
        ]);
    }

    /**
     * The built-in rule behind Virtual Printer alerts, created on first use so
     * it can be switched off under Alert Rules like any other rule.
     */
    public static function virtualPrinterDown(): self
    {
        return self::query()->firstOrCreate(['metric' => AlertMetric::VirtualPrinterDown], [
            'name' => config('devices.watched_apps.virtual_printer.alert_rule_name'),
            'operator' => AlertOperator::GreaterThan,
            'threshold' => 0,
            'duration_minutes' => 0,
            'severity' => AlertSeverity::from(config('devices.watched_apps.virtual_printer.alert_severity')),
            'is_active' => true,
        ]);
    }

    /**
     * The built-in rule behind printer problem alerts, created on first use so
     * it can be switched off under Alert Rules like any other rule.
     */
    public static function printerProblem(): self
    {
        return self::builtIn(AlertMetric::PrinterProblem, config('printers.alerts.printer_problem'));
    }

    /**
     * The built-in rule behind stopped print spooler alerts, created on first
     * use so it can be switched off under Alert Rules like any other rule.
     */
    public static function spoolerDown(): self
    {
        return self::builtIn(AlertMetric::SpoolerDown, config('printers.alerts.spooler_down'));
    }

    /**
     * The built-in rule behind overdue or failed backup alerts, created on
     * first use so it can be switched off under Alert Rules like any other rule.
     */
    public static function backupOverdue(): self
    {
        return self::builtIn(AlertMetric::BackupOverdue, config('backup.alert'));
    }

    /**
     * The built-in rule behind server backup alerts (overdue, failed, shrunk,
     * unreadable or missing), created on first use so it can be switched off
     * under Alert Rules like any other rule.
     */
    public static function serverBackupProblem(): self
    {
        return self::builtIn(AlertMetric::ServerBackupProblem, config('backup.servers.alert'));
    }

    /**
     * The built-in rule behind failed Netdata repair alerts, created on first
     * use so it can be switched off under Alert Rules like any other rule.
     */
    public static function netdataRepairFailed(): self
    {
        return self::builtIn(AlertMetric::NetdataRepairFailed, config('devices.metrics.netdata_repair_alert'));
    }

    /**
     * @param  array{rule_name: string, severity: string}  $settings
     */
    private static function builtIn(AlertMetric $metric, array $settings): self
    {
        return self::query()->firstOrCreate(['metric' => $metric], [
            'name' => $settings['rule_name'],
            'operator' => AlertOperator::GreaterThan,
            'threshold' => 0,
            'duration_minutes' => 0,
            'severity' => AlertSeverity::from($settings['severity']),
            'is_active' => true,
        ]);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function conditionLabel(): string
    {
        return $this->metric->isThresholdBased()
            ? "{$this->metric->label()} {$this->operator->label()} {$this->threshold}{$this->metric->unit()}"
            : $this->metric->label();
    }

    public function durationLabel(): string
    {
        return $this->metric->isThresholdBased() ? "{$this->duration_minutes} min" : '—';
    }
}
