<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

final class RecordTwoFactorChange
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    public function handle(TwoFactorAuthenticationEnabled|TwoFactorAuthenticationConfirmed|TwoFactorAuthenticationDisabled $event): void
    {
        $action = match ($event::class) {
            TwoFactorAuthenticationEnabled::class => AuditAction::TwoFactorEnabled,
            TwoFactorAuthenticationConfirmed::class => AuditAction::TwoFactorConfirmed,
            TwoFactorAuthenticationDisabled::class => AuditAction::TwoFactorDisabled,
        };

        ($this->recordAuditEvent)($action, $event->user, ['label' => $event->user->email], $event->user);
    }
}
