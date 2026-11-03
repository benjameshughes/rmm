<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use App\Enums\SignalColor;

/**
 * One problem on a dashboard attention row: what it is, its signal colour, and the detail on hover.
 */
final readonly class AttentionChip
{
    public function __construct(
        public string $label,
        public SignalColor $signal,
        public ?string $detail = null,
    ) {}
}
