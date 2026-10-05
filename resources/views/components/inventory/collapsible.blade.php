@props(['table', 'heading', 'name'])

<flux:accordion.item>
    <flux:accordion.heading>{{ $heading }} ({{ $table->count() }})</flux:accordion.heading>
    <flux:accordion.content>
        <x-inventory.table :table="$table" :name="$name" />
    </flux:accordion.content>
</flux:accordion.item>
