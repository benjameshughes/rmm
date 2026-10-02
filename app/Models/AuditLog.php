<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use App\Events\AuditLogged;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Append-only: rows are written by RecordAuditEvent and only ever leave through pruning.
 */
final class AuditLog extends Model
{
    /** @use HasFactory<\Database\Factories\AuditLogFactory> */
    use HasFactory;

    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'properties',
        'ip',
        'user_agent',
    ];

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => AuditLogged::class,
    ];

    protected static function booted(): void
    {
        self::updating(fn (): bool => false);
        self::deleting(fn (): bool => false);
    }

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'properties' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays((int) config('audit.retention_days')));
    }

    public static function hasSignedInFrom(User $user, ?string $ip, ?string $userAgent): bool
    {
        return self::query()
            ->whereIn('action', [AuditAction::Login, AuditAction::LoginFromNewDevice])
            ->where('user_id', $user->id)
            ->where('ip', $ip)
            ->where('user_agent', $userAgent)
            ->exists();
    }

    /**
     * "system" picks rows nobody signed in did; a user id picks that user's rows.
     */
    public function scopeByActor($query, string $actor)
    {
        return $query
            ->when($actor === 'system', fn (Builder $q) => $q->whereNull('user_id'))
            ->when(is_numeric($actor), fn (Builder $q) => $q->where('user_id', (int) $actor));
    }

    public function scopeSearch($query, string $term)
    {
        return $query->when(trim($term) !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where('properties->label', 'like', '%'.trim($term).'%')
            ->orWhere('ip', 'like', '%'.trim($term).'%')));
    }

    /**
     * A new user does not need a bell telling them they were created.
     */
    public function shouldNotify(User $user): bool
    {
        return ! ($this->action === AuditAction::UserCreated && $this->subject_id === $user->id);
    }

    public function actorName(): string
    {
        return $this->user?->name ?? $this->properties['actor'] ?? $this->action->unattributedActorName();
    }

    public function subjectLabel(): string
    {
        return $this->properties['label'] ?? '—';
    }

    public function summary(): string
    {
        return collect([
            $this->actorName(),
            $this->subjectLabel() === $this->actorName() ? null : $this->subjectLabel(),
            $this->ip === null ? null : "from {$this->ip}",
        ])->filter()->implode(' · ');
    }

    /**
     * One line of what changed, for the audit table.
     */
    public function detailsSummary(): string
    {
        $properties = collect($this->properties ?? []);

        $details = collect($properties->get('changes', []))
            ->map(fn (array $change, string $attribute): string => "{$attribute}: {$this->presentValue($change['from'])} → {$this->presentValue($change['to'])}")
            ->values()
            ->merge(collect($properties->get('secrets_changed', []))->map(fn (string $attribute): string => "{$attribute} changed"))
            ->merge(collect(['command', 'email', 'mac_addresses'])
                ->filter(fn (string $key): bool => $properties->has($key))
                ->map(fn (string $key): string => "{$key}: {$this->presentValue($properties->get($key))}"));

        return Str::limit($details->implode(', '), (int) config('audit.details_max_length'));
    }

    private function presentValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'none',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }
}
