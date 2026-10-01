<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertOperator: string
{
    case GreaterThan = 'gt';
    case LessThan = 'lt';
    case GreaterThanOrEqual = 'gte';
    case LessThanOrEqual = 'lte';

    public function evaluate(float $actual, float $threshold): bool
    {
        return match ($this) {
            self::GreaterThan => $actual > $threshold,
            self::LessThan => $actual < $threshold,
            self::GreaterThanOrEqual => $actual >= $threshold,
            self::LessThanOrEqual => $actual <= $threshold,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GreaterThan => '>',
            self::LessThan => '<',
            self::GreaterThanOrEqual => '>=',
            self::LessThanOrEqual => '<=',
        };
    }
}
