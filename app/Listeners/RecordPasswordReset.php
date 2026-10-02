<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

final class RecordPasswordReset
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    public function handle(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        ($this->recordAuditEvent)(AuditAction::PasswordReset, $event->user, ['label' => $event->user->email], $event->user);
    }
}
