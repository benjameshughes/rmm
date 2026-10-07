<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How one backup run ended, from restic's exit code: 0 backed up, 3 backed
 * up but some files could not be read, anything else no snapshot.
 */
enum BackupRunStatus: string
{
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';

    public static function fromExitCode(?int $exitCode): self
    {
        return match ($exitCode) {
            0 => self::Succeeded,
            3 => self::Partial,
            default => self::Failed,
        };
    }

    /**
     * A partial run still saved a snapshot, so it counts as a good backup.
     */
    public function isGood(): bool
    {
        return $this !== self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => 'Backed up',
            self::Partial => 'Some files skipped',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'green',
            self::Partial => 'amber',
            self::Failed => 'red',
        };
    }
}
