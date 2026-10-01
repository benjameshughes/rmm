<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommandStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceCommand extends Model
{
    use HasFactory;

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
            'status' => CommandStatus::class,
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
        return $this->status === CommandStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status->isTerminal();
    }

    public function isSuccessful(): bool
    {
        return $this->status === CommandStatus::Completed && $this->exit_code === 0;
    }

    public function markAsSent(): void
    {
        $this->update([
            'status' => CommandStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function markAsRunning(): void
    {
        $this->update([
            'status' => CommandStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(string $output, int $exitCode): void
    {
        $this->update([
            'status' => $exitCode === 0 ? CommandStatus::Completed : CommandStatus::Failed,
            'output' => $output,
            'exit_code' => $exitCode,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage, ?string $output = null, ?int $exitCode = null): void
    {
        $this->update([
            'status' => CommandStatus::Failed,
            'error_message' => $errorMessage,
            'output' => $output,
            'exit_code' => $exitCode,
            'completed_at' => now(),
        ]);
    }

    public function markAsTimedOut(?string $output = null, ?int $exitCode = null): void
    {
        $this->update([
            'status' => CommandStatus::TimedOut,
            'error_message' => "Command timed out after {$this->timeout_seconds} seconds",
            'output' => $output,
            'exit_code' => $exitCode,
            'completed_at' => now(),
        ]);
    }

    public function cancel(): void
    {
        if (! $this->isCompleted()) {
            $this->update([
                'status' => CommandStatus::Cancelled,
                'completed_at' => now(),
            ]);
        }
    }

    public function scopePending($query)
    {
        return $query->where('status', CommandStatus::Pending);
    }

    public function scopeForDevice($query, int $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }
}
