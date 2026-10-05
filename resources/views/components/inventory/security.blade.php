@props(['security'])

<div class="grid gap-6 md:grid-cols-2">
    <flux:card>
        <flux:heading size="sm" class="mb-4">Security</flux:heading>
        <x-inventory.checks :checks="$security->checks()" />
    </flux:card>

    <div class="space-y-6">
        <flux:card>
            <flux:heading size="sm" class="mb-4">BitLocker</flux:heading>
            <x-inventory.checks :checks="$security->bitlocker()" empty="BitLocker is not available on this edition of Windows." />
        </flux:card>

        <flux:card>
            <flux:heading size="sm" class="mb-4">Firewall</flux:heading>
            <x-inventory.checks :checks="$security->firewall()" />
        </flux:card>
    </div>
</div>
