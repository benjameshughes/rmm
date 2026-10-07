<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Events\AuditLogged;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SecurityEvent;
use Illuminate\Support\Facades\Notification;

final class NotifyUsersOfSecurityEvent
{
    public function handle(AuditLogged $event): void
    {
        if (! AuditAction::from($event->action)->isSecurityEvent()) {
            return;
        }

        $auditLog = AuditLog::query()->with('user')->find($event->auditLogId);

        if ($auditLog === null) {
            return;
        }

        $recipients = User::query()->humans()->get()
            ->filter(fn (User $user): bool => $user->can('viewAny', AuditLog::class) && $auditLog->shouldNotify($user));

        Notification::send($recipients, new SecurityEvent($auditLog));
    }
}
