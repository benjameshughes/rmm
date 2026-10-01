<?php

declare(strict_types=1);

namespace App\Enums;

enum ApiKeyState: string
{
    case None = 'none';
    case AwaitingAgent = 'awaiting_agent';
    case Claimed = 'claimed';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::AwaitingAgent => 'Awaiting agent',
            self::Claimed => 'Claimed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::None => 'zinc',
            self::AwaitingAgent => 'amber',
            self::Claimed => 'green',
        };
    }
}
