<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PathKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * A file or folder moved into C:\ProgramData\RMM\Quarantine on a PC. It
 * still takes up space there until it is purged, or goes back where it
 * came from when restored.
 */
final class DeviceQuarantine extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceQuarantineFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id',
        'device_command_id',
        'path',
        'kind',
        'folder',
        'quarantined_to',
        'bytes',
        'files',
        'quarantined_at',
        'purge_after',
        'purged_at',
        'restored_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PathKind::class,
            'bytes' => 'integer',
            'files' => 'integer',
            'quarantined_at' => 'datetime',
            'purge_after' => 'datetime',
            'purged_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class, 'device_command_id');
    }

    /**
     * Still sitting in quarantine: neither purged nor restored.
     */
    #[Scope]
    protected function held(Builder $query): void
    {
        $query->whereNull('purged_at')->whereNull('restored_at');
    }

    /**
     * Held past its purge date.
     */
    #[Scope]
    protected function due(Builder $query): void
    {
        $query->held()->where('purge_after', '<=', now());
    }

    public function isHeld(): bool
    {
        return $this->purged_at === null && $this->restored_at === null;
    }

    public function bytesForHumans(): string
    {
        return Number::fileSize($this->bytes, precision: 1);
    }

    /**
     * "103.7 GB · 1,200 files · quarantined 2 days ago · purged in 5 days".
     */
    public function summaryForHumans(): string
    {
        $purge = $this->purge_after->isPast() ? 'purged at the next daily run' : 'purged in '.$this->purge_after->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, options: CarbonInterface::ROUND);

        return collect([
            $this->bytesForHumans(),
            Number::format($this->files).' '.Str::plural('file', $this->files),
            'quarantined '.$this->quarantined_at->diffForHumans(),
            $purge,
        ])->implode(' · ');
    }

    public function purgeConfirmation(): string
    {
        return "Permanently delete {$this->path} ({$this->bytesForHumans()}) from quarantine now? There is no undo.";
    }
}
