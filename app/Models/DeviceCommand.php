<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommandStatus;
use App\Enums\PackageAction;
use App\Events\CommandUpdated;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Scripts that report data print it as one JSON object on their last
     * line of stdout. Null when that line is missing or not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    public function resultJson(): ?array
    {
        $lastLine = Str::of($this->stdout())->trim()->explode("\n")->last();
        $decoded = json_decode(trim((string) $lastLine), true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    public function durationForHumans(): ?string
    {
        $startedAt = $this->started_at ?? $this->sent_at;

        if ($startedAt === null || $this->completed_at === null) {
            return null;
        }

        return $startedAt->diffForHumans($this->completed_at, CarbonInterface::DIFF_ABSOLUTE, short: true, parts: 2);
    }

    /**
     * When the agent started on it, or failing that when it fetched it.
     */
    public function startedForHumans(): ?string
    {
        return ($this->started_at ?? $this->sent_at)?->diffForHumans();
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

    /**
     * Hands the command to the agent only if it is still pending, so a cancel that
     * lands between the agent's read and this write is never undone.
     */
    public function markAsSent(): bool
    {
        return $this->transitionFromPending([
            'status' => CommandStatus::Sent,
            'sent_at' => $this->freshTimestamp(),
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

    /**
     * Cancels only while the agent has not fetched it, so a fetch landing between
     * page render and click wins and is never overwritten.
     */
    public function cancel(): bool
    {
        return $this->transitionFromPending([
            'status' => CommandStatus::Cancelled,
            'completed_at' => $this->freshTimestamp(),
        ]);
    }

    /**
     * Fetching and cancelling race each other, so the pending check rides in the
     * UPDATE itself and only one of them wins. A query update skips model events,
     * so they are fired here by hand: the CommandUpdated broadcast and the audit
     * row hang off them. The loser is refreshed to whatever the winner wrote.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transitionFromPending(array $attributes): bool
    {
        $attributes = [...$attributes, 'updated_at' => $this->freshTimestamp()];

        $transitioned = self::query()->whereKey($this->getKey())->pending()->update($attributes) === 1;

        if (! $transitioned) {
            $this->refresh();

            return false;
        }

        $this->forceFill($attributes)->syncChanges();
        $this->fireModelEvent('updated', false);
        $this->syncOriginal();

        return true;
    }

    /**
     * One short line for a list: "Queued: Restart", "Running Restart", "Uninstalling...".
     */
    public function activityLabel(): string
    {
        $packageAction = $this->packageAction();

        return match (true) {
            $this->isPending() => 'Queued: '.($packageAction?->label() ?? $this->displayName()),
            $packageAction !== null => $packageAction->inProgressLabel().'...',
            default => 'Running '.$this->displayName(),
        };
    }

    /**
     * The software change this command makes, when it is one.
     */
    public function packageAction(): ?PackageAction
    {
        return PackageAction::tryFrom((string) $this->script?->slug);
    }

    /**
     * Not finished yet: pending, sent or running.
     */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereNotIn('status', collect(CommandStatus::cases())->filter->isTerminal()->all());
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
