<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

/**
 * One security setting with the badge it earns: green when sound, amber when
 * worth a look, red when unsafe and zinc when it could not be read.
 */
final class InventoryCheck
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        public readonly string $color,
    ) {}
}
