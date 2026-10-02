<?php

declare(strict_types=1);

namespace App\Actions\Script;

use App\DTOs\ScriptParameter;
use App\Models\Script;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ValidateScriptParameterValues
{
    public function __construct(
        private ValidatorFactory $validator,
    ) {}

    /**
     * Validate values entered for a script's parameters and turn them into the
     * strings the agent receives. Missing values fall back to the parameter's
     * default; blank optional values are left out.
     *
     * @param  array<string, mixed>  $values  Keyed by parameter name
     * @param  string  $attribute  The form field holding the values, so errors land on the right inputs
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function __invoke(Script $script, array $values, string $attribute = 'parameterValues'): array
    {
        $parameters = $script->parameters;
        $values = [...$this->defaults($parameters), ...$values];

        $this->validator->make(
            [$attribute => $values],
            $this->rules($parameters, $attribute),
            $this->messages($attribute),
            $this->attributeNames($parameters, $attribute),
        )->validate();

        return $parameters
            ->mapWithKeys(fn (ScriptParameter $parameter): array => [$parameter->name => $parameter->transportValue($values[$parameter->name] ?? null)])
            ->reject(fn (?string $value): bool => $value === null)
            ->all();
    }

    /**
     * @param  Collection<int, ScriptParameter>  $parameters
     * @return array<string, string>
     */
    private function defaults(Collection $parameters): array
    {
        return $parameters
            ->reject(fn (ScriptParameter $parameter): bool => $parameter->default === null)
            ->mapWithKeys(fn (ScriptParameter $parameter): array => [$parameter->name => $parameter->default])
            ->all();
    }

    /**
     * Unknown keys are refused: a script with no parameters takes none, and
     * otherwise the values array may only hold the declared names.
     *
     * @param  Collection<int, ScriptParameter>  $parameters
     * @return array<string, array<int, mixed>>
     */
    private function rules(Collection $parameters, string $attribute): array
    {
        $container = $parameters->isEmpty()
            ? ['prohibited']
            : ['array:'.$parameters->pluck('name')->implode(',')];

        return $parameters
            ->mapWithKeys(fn (ScriptParameter $parameter): array => ["{$attribute}.{$parameter->name}" => $parameter->valueRules()])
            ->prepend($container, $attribute)
            ->all();
    }

    /** @return array<string, string> */
    private function messages(string $attribute): array
    {
        return [
            "{$attribute}.prohibited" => 'This script does not take any parameters.',
            "{$attribute}.array" => 'Only the parameters this script declares can be set.',
            "{$attribute}.*.required" => ':attribute is required.',
            "{$attribute}.*.numeric" => ':attribute must be a number.',
            "{$attribute}.*.boolean" => ':attribute must be on or off.',
            "{$attribute}.*.in" => 'Choose one of the listed options for :attribute.',
            "{$attribute}.*.max" => ':attribute may not be longer than :max characters.',
            "{$attribute}.*.not_regex" => ':attribute contains characters that cannot be passed to a script.',
            "{$attribute}.*.string" => ':attribute must be text.',
        ];
    }

    /**
     * @param  Collection<int, ScriptParameter>  $parameters
     * @return array<string, string>
     */
    private function attributeNames(Collection $parameters, string $attribute): array
    {
        return $parameters
            ->mapWithKeys(fn (ScriptParameter $parameter): array => ["{$attribute}.{$parameter->name}" => $parameter->label])
            ->all();
    }
}
