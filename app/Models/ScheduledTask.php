<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Enums\ScheduledTaskAction;
use App\Enums\ScheduleTargetType;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

final class ScheduledTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'action',
        'script_id',
        'cron_expression',
        'target_type',
        'target_id',
        'is_active',
        'last_run_at',
        'next_run_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'action' => ScheduledTaskAction::class,
            'target_type' => ScheduleTargetType::class,
        ];
    }

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isDue(): bool
    {
        return (new CronExpression($this->cron_expression))->isDue();
    }

    public function resolveDevices(): Collection
    {
        return match ($this->target_type) {
            ScheduleTargetType::All => Device::query()->where('status', DeviceStatus::Active)->get(),
            ScheduleTargetType::Group => Device::query()->where('status', DeviceStatus::Active)->where('device_group_id', $this->target_id)->get(),
            ScheduleTargetType::Tag => Device::query()->where('status', DeviceStatus::Active)->whereHas('tags', fn ($q) => $q->where('tags.id', $this->target_id))->get(),
            ScheduleTargetType::Device => Device::query()->where('status', DeviceStatus::Active)->where('id', $this->target_id)->get(),
        };
    }

    public function calculateNextRun(): void
    {
        $cron = new CronExpression($this->cron_expression);
        $this->update(['next_run_at' => $cron->getNextRunDate()]);
    }
}
