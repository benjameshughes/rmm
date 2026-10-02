<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ScriptParameterType;
use Illuminate\Validation\Rule;
use JsonSerializable;

/**
 * One parameter a script declares. The agent exposes its value to the script
 * as the `RMM_<name>` environment variable.
 */
final class ScriptParameter implements JsonSerializable
{
    /**
     * @param  array<int, string>  $options  The allowed values of a Choice parameter
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly ScriptParameterType $type,
        public readonly bool $isRequired,
        public readonly ?string $default = null,
        public readonly array $options = [],
    ) {}

    /**
     * @param  array{name: string, label: string, type: string, required?: bool, default?: ?string, options?: array<int, string>}  $parameter
     */
    public static function fromArray(array $parameter): self
    {
        return new self(
            name: $parameter['name'],
            label: $parameter['label'],
            type: ScriptParameterType::from($parameter['type']),
            isRequired: (bool) ($parameter['required'] ?? false),
            default: $parameter['default'] ?? null,
            options: array_values($parameter['options'] ?? []),
        );
    }

    /**
     * @return array{name: string, label: string, type: string, required: bool, default: ?string, options: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type->value,
            'required' => $this->isRequired,
            'default' => $this->default,
            'options' => $this->options,
        ];
    }

    /**
     * @return array{name: string, label: string, type: string, required: bool, default: ?string, options: array<int, string>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<int, mixed> */
    public function valueRules(): array
    {
        return [
            $this->isRequired ? 'required' : 'nullable',
            ...$this->type->valueRules(),
            ...($this->type === ScriptParameterType::Choice ? [Rule::in($this->options)] : []),
        ];
    }

    /**
     * The value a form input shows: a previously stored value, else the
     * default, else blank. Switches need a real boolean.
     */
    public function inputValue(?string $storedValue = null): string|bool
    {
        $value = $storedValue ?? $this->default;

        return match (true) {
            $value === null => $this->type->blankInput(),
            $this->type === ScriptParameterType::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    /**
     * The value sent to the agent, or null when it was left blank.
     */
    public function transportValue(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : $this->type->normalise($value);
    }
}
