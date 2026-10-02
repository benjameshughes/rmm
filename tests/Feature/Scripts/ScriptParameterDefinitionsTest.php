<?php

declare(strict_types=1);

use App\DTOs\ScriptParameter;
use App\Enums\ScriptCategory;
use App\Enums\ScriptParameterType;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use App\Livewire\Scripts\Create;
use App\Livewire\Scripts\Edit;
use App\Livewire\Scripts\Show;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

function filledScriptForm(): Testable
{
    return Livewire::actingAs(User::factory()->create())
        ->test(Create::class)
        ->set('name', 'Install Package')
        ->set('category', ScriptCategory::Updates->value)
        ->set('platform', ScriptPlatform::Windows->value)
        ->set('script_type', ScriptType::Powershell->value)
        ->set('script_content', 'winget install --id $env:RMM_PackageId');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{name: string, label: string, type: string, required: bool, default: string, options: string}
 */
function parameterRow(array $overrides = []): array
{
    return [
        'name' => 'PackageId',
        'label' => 'Package ID',
        'type' => 'text',
        'required' => true,
        'default' => '',
        'options' => '',
        ...$overrides,
    ];
}

it('saves parameter definitions with a new script', function (): void {
    filledScriptForm()
        ->call('addParameter')
        ->call('addParameter')
        ->set('parameterRows.0', parameterRow())
        ->set('parameterRows.1', parameterRow(['name' => 'Scope', 'label' => 'Scope', 'type' => 'choice', 'required' => false, 'default' => 'machine', 'options' => 'machine, user, ,machine']))
        ->call('save')
        ->assertHasNoErrors();

    $parameters = Script::query()->where('name', 'Install Package')->sole()->parameters;

    expect($parameters)->toHaveCount(2);
    expect($parameters->first())->toBeInstanceOf(ScriptParameter::class);
    expect($parameters->first()->toArray())->toBe([
        'name' => 'PackageId',
        'label' => 'Package ID',
        'type' => 'text',
        'required' => true,
        'default' => null,
        'options' => [],
    ]);
    expect($parameters->last()->type)->toBe(ScriptParameterType::Choice);
    expect($parameters->last()->options)->toBe(['machine', 'user']);
    expect($parameters->last()->default)->toBe('machine');
});

it('stores null when a script has no parameters', function (): void {
    filledScriptForm()->call('save')->assertHasNoErrors();

    $script = Script::query()->where('name', 'Install Package')->sole();

    expect($script->getRawOriginal('parameters'))->toBeNull();
    expect($script->parameters)->toBeEmpty();
});

it('rejects bad parameter names', function (string $name): void {
    filledScriptForm()
        ->call('addParameter')
        ->set('parameterRows.0', parameterRow(['name' => $name]))
        ->call('save')
        ->assertHasErrors(['parameterRows.0.name']);

    expect(Script::query()->where('name', 'Install Package')->exists())->toBeFalse();
})->with([
    'empty' => [''],
    'starts with a digit' => ['1Package'],
    'starts with an underscore' => ['_Package'],
    'has a space' => ['Package Id'],
    'has an equals sign' => ['A=B'],
    'has a dash' => ['package-id'],
    'too long' => [str_repeat('a', 65)],
]);

it('rejects duplicate parameter names ignoring case', function (): void {
    filledScriptForm()
        ->call('addParameter')
        ->call('addParameter')
        ->set('parameterRows.0', parameterRow())
        ->set('parameterRows.1', parameterRow(['name' => 'packageid', 'label' => 'Again']))
        ->call('save')
        ->assertHasErrors(['parameterRows.0.name' => 'Each parameter needs its own name. Names ignore case.']);
});

it('requires options for a choice parameter', function (): void {
    filledScriptForm()
        ->call('addParameter')
        ->set('parameterRows.0', parameterRow(['type' => 'choice', 'options' => '']))
        ->call('save')
        ->assertHasErrors(['parameterRows.0.options' => 'List the options to choose from, separated by commas.']);
});

it('rejects a choice default that is not one of its options', function (): void {
    filledScriptForm()
        ->call('addParameter')
        ->set('parameterRows.0', parameterRow(['type' => 'choice', 'options' => 'machine, user', 'default' => 'everyone']))
        ->call('save')
        ->assertHasErrors(['parameterRows.0.default' => 'The default must be one of the options.']);
});

it('accepts a choice default that is one of its options', function (): void {
    filledScriptForm()
        ->call('addParameter')
        ->set('parameterRows.0', parameterRow(['type' => 'choice', 'options' => 'machine, user', 'default' => 'user']))
        ->call('save')
        ->assertHasNoErrors();
});

it('requires a label and a known type', function (): void {
    filledScriptForm()
        ->call('addParameter')
        ->set('parameterRows.0', parameterRow(['label' => '', 'type' => 'date']))
        ->call('save')
        ->assertHasErrors(['parameterRows.0.label', 'parameterRows.0.type']);
});

it('removes a parameter row', function (): void {
    filledScriptForm()
        ->call('addParameter')
        ->call('addParameter')
        ->set('parameterRows.0', parameterRow(['name' => 'First']))
        ->set('parameterRows.1', parameterRow(['name' => 'Second']))
        ->call('removeParameter', 0)
        ->assertSet('parameterRows.0.name', 'Second')
        ->assertCount('parameterRows', 1);
});

it('loads, edits and clears parameters on an existing script', function (): void {
    $script = Script::factory()->create([
        'parameters' => [['name' => 'PackageId', 'label' => 'Package ID', 'type' => 'text', 'required' => true]],
    ]);

    $component = Livewire::actingAs(User::factory()->create())
        ->test(Edit::class, ['script' => $script])
        ->assertSet('parameterRows.0.name', 'PackageId')
        ->assertSet('parameterRows.0.required', true)
        ->set('parameterRows.0.label', 'Winget ID')
        ->call('save')
        ->assertHasNoErrors();

    expect($script->fresh()->parameters->first()->label)->toBe('Winget ID');

    $component->call('removeParameter', 0)->call('save')->assertHasNoErrors();

    expect($script->fresh()->parameters)->toBeEmpty();
});

it('validates parameter definitions on update', function (): void {
    $script = Script::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(Edit::class, ['script' => $script])
        ->call('addParameter')
        ->set('parameterRows.0', parameterRow(['name' => 'bad name']))
        ->call('save')
        ->assertHasErrors(['parameterRows.0.name']);
});

it('lists a script\'s parameters on its page', function (): void {
    $script = Script::factory()->create([
        'parameters' => [
            ['name' => 'PackageId', 'label' => 'Package ID', 'type' => 'text', 'required' => true],
            ['name' => 'Scope', 'label' => 'Install scope', 'type' => 'choice', 'required' => false, 'default' => 'machine', 'options' => ['machine', 'user']],
        ],
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(Show::class, ['script' => $script])
        ->assertSee('RMM_PackageId')
        ->assertSee('Package ID')
        ->assertSee('Install scope')
        ->assertSee('machine, user');
});
