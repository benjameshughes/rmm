<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The few colours the dashboard is allowed to use as a signal, keyed by the Flux colour
 * names the models and enums already return, with the Tailwind classes for each place they appear.
 */
enum SignalColor: string
{
    case Green = 'green';
    case Red = 'red';
    case Amber = 'amber';
    case Sky = 'sky';
    case Zinc = 'zinc';

    /**
     * A Flux colour name from a model or enum, falling back to grey for anything this enum does not signal.
     */
    public static function fromFluxColor(string $color): self
    {
        return self::tryFrom($color) ?? self::Zinc;
    }

    public function squareClass(): string
    {
        return match ($this) {
            self::Green => 'bg-green-500',
            self::Red => 'bg-red-500',
            self::Amber => 'bg-amber-500',
            self::Sky => 'bg-sky-500',
            self::Zinc => 'bg-zinc-400 dark:bg-zinc-500',
        };
    }

    public function textClass(): string
    {
        return match ($this) {
            self::Red => 'text-red-600 dark:text-red-400',
            self::Amber => 'text-amber-600 dark:text-amber-400',
            self::Green => 'text-green-600 dark:text-green-400',
            self::Sky, self::Zinc => 'text-zinc-500 dark:text-zinc-400',
        };
    }

    /**
     * A big problem figure: faded when it is zero, otherwise in this colour.
     */
    public function figureClass(int $value): string
    {
        return $value === 0 ? 'text-zinc-300 dark:text-zinc-600' : $this->textClass();
    }
}
