<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertMetric;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Events\AlertChanged;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Alert extends Model
{
    use HasFactory;

    protected $fillable = [
        'alert_rule_id',
        'device_id',
        'status',
        'severity',
        'metric',
        'threshold',
        'current_value',
        'message',
        'triggered_at',
        'acknowledged_at',
        'acknowledged_by',
        'resolved_at',
    ];

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => AlertChanged::class,
        'updated' => AlertChanged::class,
    ];

    protected function casts(): array
    {
        return [
            'status' => AlertStatus::class,
            'severity' => AlertSeverity::class,
            'metric' => AlertMetric::class,
            'threshold' => 'float',
            'current_value' => 'float',
            'triggered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function acknowledge(User $user): void
    {
        if ($this->status !== AlertStatus::Triggered) {
            return;
        }

        $this->update([
            'status' => AlertStatus::Acknowledged,
            'acknowledged_at' => now(),
            'acknowledged_by' => $user->id,
        ]);
    }

    public function resolve(): void
    {
        if ($this->status === AlertStatus::Resolved) {
            return;
        }

        $this->update([
            'status' => AlertStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    public function conditionLabel(): string
    {
        return $this->metric->isThresholdBased()
            ? "{$this->metric->label()} {$this->alertRule?->operator->label()} {$this->threshold}{$this->metric->unit()}"
            : $this->metric->label();
    }

    /**
     * Alerts that are not threshold based store placeholder numbers, so their message is the meaningful value.
     */
    public function valueLabel(): string
    {
        return $this->metric->isThresholdBased()
            ? round($this->current_value, 1).$this->metric->unit()
            : $this->message;
    }

    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', [AlertStatus::Triggered, AlertStatus::Acknowledged]);
    }

    public function scopeTriggered($query)
    {
        return $query->where('status', AlertStatus::Triggered);
    }
}
