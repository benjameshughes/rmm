<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Auth\Events\Logout;

final class RecordLogout
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Logging out of an expired session fires the event with nobody to log out.
     */
    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        ($this->recordAuditEvent)(AuditAction::Logout, $event->user, ['label' => $event->user->email], $event->user);
    }
}
