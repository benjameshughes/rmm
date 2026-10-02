<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use Illuminate\Auth\Events\Failed;

final class RecordFailedLogin
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Only the attempted email is kept; the credentials array also holds the password.
     * Nobody is the actor, because a wrong password proves nothing about who typed it.
     */
    public function handle(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        ($this->recordAuditEvent)(AuditAction::LoginFailed, $event->user, [
            'label' => $email,
            'email' => $email,
        ]);
    }
}
