<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Actions\Audit\RecordModelChange;

/**
 * Records creates, meaningful updates and deletes in the audit log.
 * Which attributes are meaningful lives in config/audit.php.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            resolve(RecordModelChange::class)($model, 'created');
        });

        static::updated(function (self $model): void {
            if ($model->hasAuditedChanges()) {
                resolve(RecordModelChange::class)($model, 'updated');
            }
        });

        static::deleted(function (self $model): void {
            resolve(RecordModelChange::class)($model, 'deleted');
        });
    }

    /** @return array<int, string> */
    public function auditedAttributes(): array
    {
        return config('audit.attributes')[static::class] ?? [];
    }

    /**
     * Checked before anything is resolved, so the metric posts that touch a device every minute cost nothing here.
     */
    public function hasAuditedChanges(): bool
    {
        return collect($this->getChanges())->keys()->intersect($this->auditedAttributes())->isNotEmpty();
    }
}
