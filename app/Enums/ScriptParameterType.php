<?php

declare(strict_types=1);

namespace App\Enums;

enum ScriptParameterType: string
{
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Choice = 'choice';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Number => 'Number',
            self::Boolean => 'Yes / No',
            self::Choice => 'Choice',
        };
    }

    /**
     * Rules for a value of this type. Choice adds its own `in` rule because
     * only the parameter knows its options.
     *
     * @return array<int, string>
     */
    public function valueRules(): array
    {
        return match ($this) {
            self::Text => ['string', 'max:'.config('scripts.parameters.max_value_length'), 'not_regex:/\x00/'],
            self::Number => ['numeric'],
            self::Boolean => ['boolean'],
            self::Choice => ['string'],
        };
    }

    /**
     * Values travel to the agent as environment variables, so everything
     * becomes a string and booleans become 'true' or 'false'.
     */
    public function normalise(mixed $value): string
    {
        return match ($this) {
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            default => (string) $value,
        };
    }

    /** The empty value a form input starts with when the parameter has no default. */
    public function blankInput(): string|bool
    {
        return match ($this) {
            self::Boolean => false,
            default => '',
        };
    }
}
