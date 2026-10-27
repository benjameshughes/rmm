@props(['software'])

<x-dashboard.section id="updates" class="scroll-mt-6" title="Windows" description="Installed updates, the runtime environment, and what third parties added: anything not from Microsoft, worth a look when a PC misbehaves." data-windows-software>
    <div class="grid gap-6 md:grid-cols-2">
        <x-inventory.facts :facts="$software->environment()" />
        <div class="space-y-2">
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Optional features enabled</flux:text>
            <x-inventory.badges :items="$software->optionalFeatures()" name="feature" empty="None reported." />
        </div>
    </div>

    <flux:accordion transition>
        <x-inventory.collapsible :table="$software->hotfixes()" heading="Installed updates" name="hotfixes" />
        <x-inventory.collapsible :table="$software->drivers()" heading="Drivers" name="drivers" />
        <x-inventory.collapsible :table="$software->startup()" heading="Startup items" name="startup" />
        <x-inventory.collapsible :table="$software->services()" heading="Services" name="services" />
        <x-inventory.collapsible :table="$software->scheduledTasks()" heading="Scheduled tasks" name="scheduled-tasks" />
        <x-inventory.collapsible :table="$software->listeningPorts()" heading="Listening ports" name="listening-ports" />
    </flux:accordion>
</x-dashboard.section>
