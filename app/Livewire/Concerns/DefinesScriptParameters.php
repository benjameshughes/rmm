<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\DTOs\ScriptParameter;
use App\Enums\ScriptParameterType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The parameters editor on the script create and edit forms. Choice options
 * are typed as one comma separated line.
 */
trait DefinesScriptParameters
{
    /** @var array<int, array{name: string, label: string, type: string, required: bool, default: string, options: string}> */
    public array $parameterRows = [];

    public function addParameter(): void
    {
        $this->parameterRows[] = [
            'name' => '',
            'label' => '',
            'type' => ScriptParameterType::Text->value,
            'required' => false,
            'default' => '',
            'options' => '',
        ];
    }

    public function removeParameter(int $index): void
    {
        unset($this->parameterRows[$index]);
        $this->parameterRows = array_values($this->parameterRows);
    }

    /**
     * @param  Collection<int, ScriptParameter>  $parameters
     */
    protected function fillParameterRows(Collection $parameters): void
    {
        $this->parameterRows = $parameters
            ->map(fn (ScriptParameter $parameter): array => [
                'name' => $parameter->name,
                'label' => $parameter->label,
                'type' => $parameter->type->value,
                'required' => $parameter->isRequired,
                'default' => $parameter->default ?? '',
                'options' => implode(', ', $parameter->options),
            ])
            ->all();
    }

    /** @return array<int, ScriptParameter> */
    protected function parameterDefinitions(): array
    {
        return collect($this->parameterRows)
            ->map(fn (array $row): ScriptParameter => new ScriptParameter(
                name: trim($row['name']),
                label: trim($row['label']),
                type: ScriptParameterType::from($row['type']),
                isRequired: (bool) $row['required'],
                default: Str::of((string) $row['default'])->trim()->toString() ?: null,
                options: $row['type'] === ScriptParameterType::Choice->value ? $this->splitOptions((string) $row['options']) : [],
            ))
            ->all();
    }

    /**
     * Windows environment variable names ignore case, so PackageId and
     * packageid would collide on the device.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function parameterRules(): array
    {
        return [
            'parameterRows' => ['array', 'max:'.config('scripts.parameters.max_per_script')],
            'parameterRows.*.name' => ['required', 'string', 'regex:'.config('scripts.parameters.name_pattern'), 'distinct:ignore_case'],
            'parameterRows.*.label' => ['required', 'string', 'max:'.config('scripts.parameters.max_label_length')],
            'parameterRows.*.type' => ['required', Rule::enum(ScriptParameterType::class)],
            'parameterRows.*.required' => ['boolean'],
            'parameterRows.*.default' => ['nullable', 'string', 'max:'.config('scripts.parameters.max_value_length')],
            'parameterRows.*.options' => ['nullable', 'string', 'required_if:parameterRows.*.type,'.ScriptParameterType::Choice->value],
            ...$this->choiceDefaultRules(),
        ];
    }

    /** @return array<string, string> */
    protected function parameterMessages(): array
    {
        return [
            'parameterRows.max' => 'A script can have at most :max parameters.',
            'parameterRows.*.name.required' => 'Give the parameter a name.',
            'parameterRows.*.name.regex' => 'Names start with a letter and use only letters, digits and underscores (up to 64 characters).',
            'parameterRows.*.name.distinct' => 'Each parameter needs its own name. Names ignore case.',
            'parameterRows.*.label.required' => 'Give the parameter a label.',
            'parameterRows.*.label.max' => 'Labels may not be longer than :max characters.',
            'parameterRows.*.type.required' => 'Choose a parameter type.',
            'parameterRows.*.type.enum' => 'Choose a parameter type.',
            'parameterRows.*.default.max' => 'Defaults may not be longer than :max characters.',
            'parameterRows.*.options.required_if' => 'List the options to choose from, separated by commas.',
            'parameterRows.*.default.in' => 'The default must be one of the options.',
        ];
    }

    /**
     * A choice default that is not one of its own options would fail validation on every run.
     *
     * @return array<string, array<int, mixed>>
     */
    private function choiceDefaultRules(): array
    {
        return collect($this->parameterRows)
            ->filter(fn (array $row): bool => $row['type'] === ScriptParameterType::Choice->value && trim((string) $row['default']) !== '')
            ->mapWithKeys(fn (array $row, int $index): array => [
                "parameterRows.{$index}.default" => ['nullable', 'string', Rule::in($this->splitOptions((string) $row['options']))],
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function splitOptions(string $options): array
    {
        return Str::of($options)
            ->explode(',')
            ->map(fn (string $option): string => trim($option))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
