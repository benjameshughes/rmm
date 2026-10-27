<flux:card {{ $attributes->class('flex flex-col items-center gap-3 px-6 py-10 text-center') }} data-linux-security-empty>
    <flux:icon name="shield-check" class="size-10 text-zinc-300 dark:text-zinc-600" />
    <flux:heading>No security report yet</flux:heading>
    <flux:text class="max-w-md">The Linux agent reads pending updates, SSH settings, the firewall, failed logins and listening ports every few hours. It changes nothing. Agents before 0.10.0 do not send it.</flux:text>
</flux:card>
