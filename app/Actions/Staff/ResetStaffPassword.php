<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\StaffAccount;
use App\Models\User;

/**
 * Queues the temp password from config/staff.php on every PC the account
 * targets, to be changed at the person's next logon.
 */
final readonly class ResetStaffPassword
{
    public function __construct(
        private SyncStaffAccount $syncStaffAccount,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @return int How many PCs the reset was queued on
     */
    public function __invoke(StaffAccount $account, User $user): int
    {
        ($this->recordAuditEvent)(AuditAction::StaffAccountPasswordChanged, $account, actor: $user);

        return ($this->syncStaffAccount)($account, $user, resetPassword: true);
    }
}
