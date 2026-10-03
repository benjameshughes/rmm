<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommandStatus;
use App\Events\CommandUpdated;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class DeviceCommand extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'device_id',
        'script_id',
        'scheduled_task_id',
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
        'parameters',
    ];

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => CommandUpdated::class,
        'updated' => CommandUpdated::class,
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
            'parameters' => 'array',
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

    public function scheduledTask(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class);
    }

    public function queuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'queued_by');
    }

    public function isAdHoc(): bool
    {
        return $this->script_id === null;
    }

    /**
     * Ad-hoc commands have no script name, so the first line of what was typed
     * stands in for one. A deleted script nulls script_id, so its past runs read
     * as ad-hoc too, which is honest: the script behind them is gone.
     */
    public function displayName(): string
    {
        if (! $this->isAdHoc()) {
            return $this->script?->name ?? Str::headline($this->script_type);
        }

        $firstLine = Str::of((string) $this->script_content)
            ->explode("\n")
            ->map(fn (string $line): string => trim($line))
            ->first(fn (string $line): bool => $line !== '');

        return $firstLine === null
            ? 'Ad-hoc command'
            : 'Ad-hoc command: '.Str::limit($firstLine, config('commands.ad_hoc.label_max_length'));
    }

    public function stdout(): string
    {
        return Str::before((string) $this->output, config('commands.stderr_separator'));
    }

    public function stderr(): ?string
    {
        $separator = config('commands.stderr_separator');

        return Str::contains((string) $this->output, $separator)
            ? Str::after((string) $this->output, $separator)
            : null;
    }

    /**
     * Scripts print their one-line verdict first, so the first non-empty line
     * of stdout is the summary. Falls back to stderr, the error message, then
     * the status for commands that died without saying anything.
     */
    public function summaryLine(): string
    {
        $firstLine = fn (?string $text): ?string => Str::of((string) $text)
            ->explode("\n")
            ->map(fn (string $line): string => trim($line))
            ->first(fn (string $line): bool => $line !== '');

        $summary = $firstLine($this->stdout())
            ?? $firstLine($this->stderr())
            ?? $firstLine($this->error_message)
            ?? $this->status->label();

        return Str::limit($summary, config('alerts.scheduled_script_failed.summary_max_length'));
    }

    public function durationForHumans(): ?string
    {
        $startedAt = $this->started_at ?? $this->sent_at;

        if ($startedAt === null || $this->completed_at === null) {
            return null;
        }

        return $startedAt->diffForHumans($this->completed_at, CarbonInterface::DIFF_ABSOLUTE, short: true, parts: 2);
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

    /**
     * Put a command the agent fetched but never started back in the queue.
     */
    public function requeue(): void
    {
        $this->update([
            'status' => CommandStatus::Pending,
            'sent_at' => null,
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
