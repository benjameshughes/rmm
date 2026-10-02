@props(['parameters'])

@foreach ($parameters as $parameter)
    <div wire:key="parameter-input-{{ $parameter->name }}">
        @switch ($parameter->type->value)
            @case ('boolean')
                <flux:switch wire:model="parameterValues.{{ $parameter->name }}" :label="$parameter->label" />
                @break

            @case ('choice')
                <flux:select wire:model="parameterValues.{{ $parameter->name }}" :label="$parameter->label" placeholder="Select..." :required="$parameter->isRequired">
                    @foreach ($parameter->options as $option)
                        <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                    @endforeach
                </flux:select>
                @break

            @case ('number')
                <flux:input type="number" step="any" wire:model="parameterValues.{{ $parameter->name }}" :label="$parameter->label" :required="$parameter->isRequired" />
                @break

            @default
                <flux:input wire:model="parameterValues.{{ $parameter->name }}" :label="$parameter->label" :required="$parameter->isRequired" />
        @endswitch
    </div>
@endforeach

<flux:error name="parameterValues" />
