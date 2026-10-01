<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertMetric;
use App\Enums\AlertOperator;
use App\Enums\AlertSeverity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AlertRule extends Model
{
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

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
