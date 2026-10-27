@props(['security', 'collected' => null])

@php($posture = $security->postureCheck())
@php($authorizedKeys = $security->authorizedKeys())
<div {{ $attributes->class('space-y-6') }} data-linux-security>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:text size="sm">Security report · {{ $collected }}</flux:text>
        <flux:badge size="sm" :color="$posture->color" :icon="$posture->icon()" data-linux-posture="{{ $posture->color }}">{{ $posture->value }}</flux:badge>
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-2">
        <div class="space-y-6">
            <x-dashboard.section title="Updates" description="From a read-only apt simulation against the box's last apt update.">
                <x-inventory.checks :checks="$security->updates()" />
            </x-dashboard.section>

            <x-dashboard.section title="Firewall">
                <x-inventory.checks :checks="$security->firewall()" />
            </x-dashboard.section>
        </div>

        <div class="space-y-6">
            <x-dashboard.section title="SSH" :description="$security->sshSource()">
                <x-inventory.checks :checks="$security->ssh()" />
            </x-dashboard.section>

            <x-dashboard.section title="Failed logins">
                <x-inventory.checks :checks="$security->loginChecks()" />
                <x-inventory.table :table="$security->failedLoginSources()" name="failed-login-sources" empty="No failed logins." />
            </x-dashboard.section>
        </div>
    </div>

    <x-dashboard.section title="Access and exposure" description="Keys that can sign in as root or a person, and every port the box listens on.">
        <flux:accordion transition>
            @if($authorizedKeys)
                <x-inventory.collapsible :table="$authorizedKeys" heading="Authorized SSH keys" name="authorized-keys" />
            @endif
            <x-inventory.collapsible :table="$security->listeningPorts()" heading="Listening ports" name="listening-ports" />
        </flux:accordion>
    </x-dashboard.section>
</div>
