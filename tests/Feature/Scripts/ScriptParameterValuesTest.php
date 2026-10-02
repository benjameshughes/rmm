<?php

declare(strict_types=1);

use App\Actions\Script\ValidateScriptParameterValues;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->script = Script::factory()->create([
        'parameters' => [
            ['name' => 'PackageId', 'label' => 'Package ID', 'type' => 'text', 'required' => true],
            ['name' => 'Retries', 'label' => 'Retries', 'type' => 'number', 'required' => false, 'default' => '3'],
            ['name' => 'Force', 'label' => 'Force', 'type' => 'boolean', 'required' => false],
            ['name' => 'Scope', 'label' => 'Scope', 'type' => 'choice', 'required' => false, 'options' => ['machine', 'user']],
        ],
    ]);
    $this->validateParameters = app(ValidateScriptParameterValues::class);
});

/**
 * @param  array<string, mixed>  $values
 * @return array<string, array<int, string>>
 */
function parameterErrors(Script $script, array $values): array
{
    return rescue(
        function () use ($script, $values): array {
            app(ValidateScriptParameterValues::class)($script, $values);

            return [];
        },
        fn (ValidationException $exception): array => $exception->errors(),
        report: false,
    );
}

it('normalises values to strings for the agent', function (): void {
    expect(($this->validateParameters)($this->script, [
        'PackageId' => 'Mozilla.Firefox',
        'Retries' => 5,
        'Force' => true,
        'Scope' => 'user',
    ]))->toBe([
        'PackageId' => 'Mozilla.Firefox',
        'Retries' => '5',
        'Force' => 'true',
        'Scope' => 'user',
    ]);
});

it('sends false booleans, fills defaults and leaves out blank optional values', function (): void {
    expect(($this->validateParameters)($this->script, [
        'PackageId' => 'Git.Git',
        'Force' => false,
        'Scope' => '',
    ]))->toBe([
        'PackageId' => 'Git.Git',
        'Retries' => '3',
        'Force' => 'false',
    ]);
});

it('requires required values', function (): void {
    expect(parameterErrors($this->script, ['PackageId' => '']))
        ->toBe(['parameterValues.PackageId' => ['Package ID is required.']]);
});

it('rejects a non-numeric number', function (): void {
    expect(parameterErrors($this->script, ['PackageId' => 'Git.Git', 'Retries' => 'lots']))
        ->toBe(['parameterValues.Retries' => ['Retries must be a number.']]);
});

it('rejects a value that is not one of the choices', function (): void {
    expect(parameterErrors($this->script, ['PackageId' => 'Git.Git', 'Scope' => 'everyone']))
        ->toBe(['parameterValues.Scope' => ['Choose one of the listed options for Scope.']]);
});

it('rejects a value that is not a boolean', function (): void {
    expect(parameterErrors($this->script, ['PackageId' => 'Git.Git', 'Force' => 'maybe']))
        ->toBe(['parameterValues.Force' => ['Force must be on or off.']]);
});

it('rejects unknown parameter names', function (): void {
    expect(parameterErrors($this->script, ['PackageId' => 'Git.Git', 'Evil' => 'x']))
        ->toBe(['parameterValues' => ['Only the parameters this script declares can be set.']]);
});

it('rejects any values for a script without parameters', function (): void {
    $plain = Script::factory()->create();

    expect(parameterErrors($plain, ['Anything' => 'x']))
        ->toBe(['parameterValues' => ['This script does not take any parameters.']]);
    expect(($this->validateParameters)($plain, []))->toBe([]);
});

it('rejects overlong text and NUL bytes', function (): void {
    $tooLong = str_repeat('a', config('scripts.parameters.max_value_length') + 1);

    expect(parameterErrors($this->script, ['PackageId' => $tooLong]))->toHaveKey('parameterValues.PackageId');
    expect(parameterErrors($this->script, ['PackageId' => "Git\0Git"]))->toHaveKey('parameterValues.PackageId');
});

it('passes awkward characters through untouched', function (): void {
    $value = "it's \"quoted\" \$(whoami); & del C:\\ `n";

    expect(($this->validateParameters)($this->script, ['PackageId' => $value])['PackageId'])->toBe($value);
});

it('reports errors under the field the caller names', function (): void {
    expect(fn () => ($this->validateParameters)($this->script, [], 'values'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('values.PackageId'));
});
