<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * Which approved devices the Trends page measures.
 */
enum TrendScope: string
{
    case Windows = 'windows';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Windows => 'Windows PCs',
            self::All => 'All devices',
        };
    }

    public function noun(int $count): string
    {
        return match ($this) {
            self::Windows => $count === 1 ? 'Windows PC' : 'Windows PCs',
            self::All => str('device')->plural($count)->value(),
        };
    }

    public function apply(Builder $devices): Builder
    {
        return $this === self::Windows ? $devices->runsWindows() : $devices;
    }
}
