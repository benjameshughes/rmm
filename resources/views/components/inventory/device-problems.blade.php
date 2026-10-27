@props(['hardware'])

<x-dashboard.section id="device-problems" class="scroll-mt-6" title="Device problems" description="Devices Windows says are not working, from Device Manager.">
    <x-inventory.checks :checks="$hardware->deviceProblems()" empty="Not reported. Run the inventory again to check." />
</x-dashboard.section>
