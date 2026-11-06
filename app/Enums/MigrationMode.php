<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where Migrate User takes the user from: another PC, through its backup
 * repository, or another account on this same PC.
 */
enum MigrationMode: string
{
    case FromAnotherPc = 'another-pc';
    case OnThisPc = 'this-pc';

    public function label(): string
    {
        return match ($this) {
            self::FromAnotherPc => 'From another PC',
            self::OnThisPc => 'On this PC',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FromAnotherPc => 'Captures the user on the old PC into its backup, then loads it here once that succeeds.',
            self::OnThisPc => 'Moves another account on this PC into the staff account, for example before leaving a domain.',
        };
    }

    public function script(): UserStateScript
    {
        return match ($this) {
            self::FromAnotherPc => UserStateScript::Capture,
            self::OnThisPc => UserStateScript::MigrateInPlace,
        };
    }
}
