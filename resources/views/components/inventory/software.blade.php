@props(['software'])

<flux:card class="space-y-4">
    <flux:heading size="sm">Software environment</flux:heading>
    <x-inventory.facts :facts="$software->environment()" />
    <flux:heading size="sm">Optional features enabled</flux:heading>
    <x-inventory.badges :items="$software->optionalFeatures()" name="feature" empty="None reported." />
</flux:card>
