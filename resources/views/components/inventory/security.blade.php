@props(['security'])

<div {{ $attributes->class('grid items-start gap-6 lg:grid-cols-2') }} data-security>
    <x-dashboard.section title="Protection" description="Secure Boot, TPM, Defender, UAC and virtualisation-based security.">
        <x-inventory.checks :checks="$security->checks()" />
    </x-dashboard.section>

    <div class="space-y-6">
        <x-dashboard.section title="BitLocker">
            <x-inventory.checks :checks="$security->bitlocker()" empty="BitLocker is not available on this edition of Windows." />
        </x-dashboard.section>

        <x-dashboard.section title="Firewall">
            <x-inventory.checks :checks="$security->firewall()" />
        </x-dashboard.section>
    </div>
</div>
