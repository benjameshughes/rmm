<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;

final class RecordAuditEvent
{
    public function __construct(
        private Guard $guard,
        private Request $request,
        private LogManager $log,
    ) {}

    /**
     * Write one audit row and mirror it to the audit log channel. The actor
     * defaults to whoever is signed in; console, scheduler and agent work has
     * nobody signed in, so it is recorded with no user.
     *
     * @param  array<string, mixed>  $properties
     */
    public function __invoke(AuditAction $action, ?Model $subject = null, array $properties = [], ?Authenticatable $actor = null): AuditLog
    {
        $actor ??= $this->guard->user();
        $user = $actor instanceof User && $actor->exists ? $actor : null;

        $auditLog = AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => [...$properties, ...($user === null ? [] : ['actor' => $user->email])] ?: null,
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);

        $this->log->channel('audit')->info($action->value, [
            'audit_log_id' => $auditLog->id,
            'user_id' => $auditLog->user_id,
            'subject_type' => $auditLog->subject_type,
            'subject_id' => $auditLog->subject_id,
            'properties' => $auditLog->properties,
            'ip' => $auditLog->ip,
            'user_agent' => $auditLog->user_agent,
        ]);

        return $auditLog;
    }
}
