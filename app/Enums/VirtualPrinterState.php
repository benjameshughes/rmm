<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a PC can print labels right now. Unwatched covers PCs that are not
 * print stations and stations that are not plainly online: an offline PC
 * already shows as such, so the watch ignores it.
 */
enum VirtualPrinterState: string
{
    case Ready = 'ready';
    case Down = 'down';
    case Unwatched = 'unwatched';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Down => 'Down',
            self::Unwatched => 'Not watched',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ready => 'green',
            self::Down => 'red',
            self::Unwatched => 'zinc',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Ready => 'printer',
            self::Down => 'exclamation-triangle',
            self::Unwatched => 'minus-circle',
        };
    }
}
