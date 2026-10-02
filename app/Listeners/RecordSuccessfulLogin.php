<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

final class RecordSuccessfulLogin
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private Request $request,
    ) {}

    /**
     * Checked before recording, so the first sign-in from an IP and browser pair is the one flagged as new.
     */
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $action = AuditLog::hasSignedInFrom($event->user, $this->request->ip(), $this->request->userAgent())
            ? AuditAction::Login
            : AuditAction::LoginFromNewDevice;

        ($this->recordAuditEvent)($action, $event->user, ['label' => $event->user->email], $event->user);
    }
}
