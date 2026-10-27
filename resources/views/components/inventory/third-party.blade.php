@props(['software'])

<flux:card>
    <flux:heading size="sm" class="mb-2">Added by third parties</flux:heading>
    <flux:text size="sm" class="mb-4">Drivers, startup items, services and tasks not from Microsoft, and what was listening for TCP connections. Worth a look when a PC misbehaves.</flux:text>
    <flux:accordion transition>
        <x-inventory.collapsible :table="$software->drivers()" heading="Drivers" name="drivers" />
        <x-inventory.collapsible :table="$software->startup()" heading="Startup items" name="startup" />
        <x-inventory.collapsible :table="$software->services()" heading="Services" name="services" />
        <x-inventory.collapsible :table="$software->scheduledTasks()" heading="Scheduled tasks" name="scheduled-tasks" />
        <x-inventory.collapsible :table="$software->listeningPorts()" heading="Listening ports" name="listening-ports" />
    </flux:accordion>
</flux:card>
