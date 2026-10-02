<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued so a slow socket never holds up a sign-in or a save.
 */
final class SecurityEvent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AuditLog $auditLog,
    ) {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array{auditLogId: int, action: string, title: string, body: string, level: string, url: string} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'auditLogId' => $this->auditLog->id,
            'action' => $this->auditLog->action->value,
            'title' => $this->auditLog->action->label(),
            'body' => $this->auditLog->summary(),
            'level' => $this->auditLog->action->notificationLevel()->value,
            'url' => route('audit.index'),
        ];
    }

    /**
     * Scalars only, so emails, IPs and names stay off the socket; the browser reads the stored copy.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'auditLogId' => $this->auditLog->id,
            'action' => $this->auditLog->action->value,
        ]);
    }
}
