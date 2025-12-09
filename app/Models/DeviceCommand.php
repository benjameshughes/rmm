<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommand extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMED_OUT = 'timed_out';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'device_id',
        'script_id',
        'script_content',
        'script_type',
        'status',
        'output',
        'exit_code',
        'error_message',
        'queued_at',
        'sent_at',
        'started_at',
        'completed_at',
        'timeout_seconds',
        'queued_by',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'timeout_seconds' => 'integer',
            'exit_code' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    public function queuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'queued_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_TIMED_OUT,
            self::STATUS_CANCELLED,
        ]);
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->exit_code === 0;
    }

    public function markAsSent(): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function markAsRunning(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(string $output, int $exitCode): void
    {
        $this->update([
            'status' => $exitCode === 0 ? self::STATUS_COMPLETED : self::STATUS_FAILED,
            'output' => $output,
            'exit_code' => $exitCode,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage, ?string $output = null, ?int $exitCode = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'output' => $output,
            'exit_code' => $exitCode,
            'completed_at' => now(),
        ]);
    }

    public function markAsTimedOut(): void
    {
        $this->update([
            'status' => self::STATUS_TIMED_OUT,
            'error_message' => "Command timed out after {$this->timeout_seconds} seconds",
            'completed_at' => now(),
        ]);
    }

    public function cancel(): void
    {
        if (! $this->isCompleted()) {
            $this->update([
                'status' => self::STATUS_CANCELLED,
                'completed_at' => now(),
            ]);
        }
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForDevice($query, int $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }
}
