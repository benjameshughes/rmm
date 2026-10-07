<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where winget found a package. Only winget-source packages can be installed or
 * upgraded by ID; Add/Remove Programs and MSIX entries can only be uninstalled.
 */
enum SoftwareSource: string
{
    case Winget = 'winget';
    case Arp = 'arp';
    case Msix = 'msix';

    public function label(): string
    {
        return match ($this) {
            self::Winget => 'winget',
            self::Arp => 'Add/Remove Programs',
            self::Msix => 'MSIX',
        };
    }

    /**
     * The package ID prefix winget gives entries it only knows locally, as a LIKE
     * pattern; `_` stands in for the backslash so no escaping is needed.
     */
    public function packageIdPattern(): ?string
    {
        return match ($this) {
            self::Winget => null,
            self::Arp => 'ARP_%',
            self::Msix => 'MSIX_%',
        };
    }
}
