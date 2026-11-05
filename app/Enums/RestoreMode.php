<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a restore puts the files: a new folder, or a staff account's profile.
 */
enum RestoreMode: string
{
    case Folder = 'folder';
    case Profile = 'profile';

    public function label(): string
    {
        return match ($this) {
            self::Folder => 'Into a folder',
            self::Profile => 'Into a staff account\'s profile',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Folder => 'Files land under a new folder by their full path.',
            self::Profile => 'Desktop, Documents and the other profile folders go into the account\'s own, skipping files already there.',
        };
    }

    public function script(): BackupScript
    {
        return match ($this) {
            self::Folder => BackupScript::Restore,
            self::Profile => BackupScript::RestoreProfile,
        };
    }
}
