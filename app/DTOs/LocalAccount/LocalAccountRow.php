<?php

declare(strict_types=1);

namespace App\DTOs\LocalAccount;

use App\Models\DeviceLocalAccount;

/**
 * One local account on a PC's Users tab, with whether a staff account
 * manages it and what may be done to it from there.
 */
final readonly class LocalAccountRow
{
    public function __construct(
        public DeviceLocalAccount $account,
        public bool $isManaged,
    ) {}

    public function canAdopt(): bool
    {
        return ! $this->isManaged && ! $this->account->isBuiltIn;
    }

    public function canDisable(): bool
    {
        return $this->canAdopt() && $this->account->is_enabled;
    }

    public function canDelete(): bool
    {
        return $this->canAdopt() && $this->account->isSignedOut;
    }

    public function lastLogonForHumans(): string
    {
        return $this->account->last_logon_at?->inDisplayTimezone()->format('j M Y, H:i') ?? 'Never';
    }

    public function lastLogonAgo(): ?string
    {
        return $this->account->last_logon_at?->diffForHumans();
    }
}
