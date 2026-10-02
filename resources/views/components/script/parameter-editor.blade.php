@props(['rows', 'types'])

<div class="space-y-3">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="sm">Parameters</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                Each value reaches the script as an environment variable named RMM_ plus the parameter name, such as $env:RMM_PackageId in PowerShell or $RMM_PackageId in bash.
            </flux:text>
        </div>
        <flux:button size="sm" icon="plus" wire:click="addParameter">Add Parameter</flux:button>
    </div>

    <flux:error name="parameterRows" />

    @foreach ($rows as $index => $row)
        <flux:card wire:key="parameter-row-{{ $index }}" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="parameterRows.{{ $index }}.name" label="Name" placeholder="e.g. PackageId" class="font-mono" />
                <flux:input wire:model="parameterRows.{{ $index }}.label" label="Label" placeholder="e.g. Package ID" />
                <flux:select wire:model.live="parameterRows.{{ $index }}.type" label="Type">
                    @foreach ($types as $type)
                        <flux:select.option value="{{ $type->value }}">{{ $type->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="parameterRows.{{ $index }}.default" label="Default" placeholder="Optional" />
                @if ($row['type'] === 'choice')
                    <flux:input wire:model="parameterRows.{{ $index }}.options" label="Options" placeholder="Comma separated, e.g. low, medium, high" />
                @endif
            </div>

            <div class="flex items-center justify-between">
                <flux:switch wire:model="parameterRows.{{ $index }}.required" label="Required" />
                <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeParameter({{ $index }})">Remove</flux:button>
            </div>
        </flux:card>
    @endforeach
</div>
