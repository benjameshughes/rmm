@props(['software'])

<flux:card class="space-y-4">
    <flux:heading size="sm">Installed updates</flux:heading>
    <x-inventory.table :table="$software->hotfixes()" name="hotfixes" empty="No installed updates reported." />
</flux:card>
