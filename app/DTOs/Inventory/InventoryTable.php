<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

/**
 * One list from a system inventory, already reduced to display strings.
 */
final class InventoryTable
{
    /**
     * @param  list<string>  $columns
     * @param  list<list<?string>>  $rows
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
