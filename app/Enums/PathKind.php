<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a path to delete is expected to be. The script refuses a path that
 * turned out to be the other kind, so a folder never goes when a file was meant.
 */
enum PathKind: string
{
    case File = 'file';
    case Folder = 'folder';
    case Any = 'any';

    public function label(): string
    {
        return match ($this) {
            self::File => 'File',
            self::Folder => 'Folder',
            self::Any => 'File or folder',
        };
    }
}
