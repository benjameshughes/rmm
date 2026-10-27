@props(['security'])

<x-dashboard.section id="security" class="scroll-mt-6" title="Security">
    <x-inventory.checks :checks="$security->checks()" />

    <flux:separator />

    <flux:heading size="sm">BitLocker</flux:heading>
    <x-inventory.checks :checks="$security->bitlocker()" empty="BitLocker is not available on this edition of Windows." />

    <flux:separator />

    <flux:heading size="sm">Firewall</flux:heading>
    <x-inventory.checks :checks="$security->firewall()" />
</x-dashboard.section>
