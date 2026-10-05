@props(['software'])

<div class="grid gap-6 lg:grid-cols-2">
    <flux:card class="space-y-4">
        <flux:heading size="sm">Updates</flux:heading>
        <div class="flex flex-wrap items-center gap-2">
            @if($software->isRebootPending())
                <flux:badge size="sm" color="amber" icon="arrow-path">Restart pending</flux:badge>
            @else
                <flux:badge size="sm" color="green">No restart pending</flux:badge>
            @endif
            <x-inventory.badges :items="$software->pendingRebootReasons()" name="reboot-reason" />
        </div>
        <x-inventory.table :table="$software->hotfixes()" name="hotfixes" empty="No installed updates reported." />
    </flux:card>

    <flux:card class="space-y-4">
        <flux:heading size="sm">Software environment</flux:heading>
        <x-inventory.facts :facts="$software->environment()" />
        <flux:heading size="sm">Optional features enabled</flux:heading>
        <x-inventory.badges :items="$software->optionalFeatures()" name="feature" empty="None reported." />
    </flux:card>
</div>

<flux:card>
    <flux:heading size="sm" class="mb-2">Added by third parties</flux:heading>
    <flux:text size="sm" class="mb-4">Anything not from Microsoft. Worth a look when a PC misbehaves or something unexpected starts with Windows.</flux:text>
    <flux:accordion transition>
        <x-inventory.collapsible :table="$software->drivers()" heading="Drivers" name="drivers" />
        <x-inventory.collapsible :table="$software->startup()" heading="Startup items" name="startup" />
        <x-inventory.collapsible :table="$software->services()" heading="Services" name="services" />
        <x-inventory.collapsible :table="$software->scheduledTasks()" heading="Scheduled tasks" name="scheduled-tasks" />
    </flux:accordion>
</flux:card>
