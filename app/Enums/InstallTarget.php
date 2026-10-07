<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which devices a bulk install goes to.
 */
enum InstallTarget: string
{
    case Selected = 'selected';
    case Group = 'group';
    case Tag = 'tag';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Selected => 'Selected devices',
            self::Group => 'A group',
            self::Tag => 'A tag',
            self::All => 'All Windows devices',
        };
    }
}
