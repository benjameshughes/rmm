<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pending updates, unattended-upgrades, SSH, firewall, failed logins and
 * listening ports from one Linux security report. Every reading is null
 * when the agent could not take it, and shows zinc rather than a guess.
 */
final class LinuxSecurity
{
    use ReadsInventoryData;

    /**
     * @param  array<string, mixed>  $data  The inventory's linux_security section
     */
    public function __construct(
        private readonly array $data,
    ) {}

    /**
     * The headline for the security tile: how many readings are amber or red.
     */
    public function postureCheck(): InventoryCheck
    {
        $concerns = $this->checks()->filter(fn (InventoryCheck $check): bool => $check->needsAttention());

        return match (true) {
            $concerns->isEmpty() => new InventoryCheck('Security', 'All good', 'green'),
            default => new InventoryCheck('Security', $concerns->count().' to check', $concerns->contains('color', 'red') ? 'red' : 'amber'),
        };
    }

    /**
     * @return Collection<int, InventoryCheck>
     */
    public function checks(): Collection
    {
        return $this->updates()->concat($this->ssh())->concat($this->firewall())->concat($this->loginChecks());
    }

    /**
     * @return Collection<int, InventoryCheck>
     */
    public function loginChecks(): Collection
    {
        return collect([$this->failedLogins()]);
    }

    /**
     * @return Collection<int, InventoryCheck>
     */
    public function updates(): Collection
    {
        return collect([$this->pendingUpdates(), $this->reboot(), $this->unattendedUpgrades()]);
    }

    /**
     * @return Collection<int, InventoryCheck>
     */
    public function ssh(): Collection
    {
        if (! is_array(data_get($this->data, 'ssh'))) {
            return collect([new InventoryCheck('SSH server', 'Not found', 'zinc')]);
        }

        $passwordAuthentication = data_get($this->data, 'ssh.password_authentication');
        $keyboardInteractive = data_get($this->data, 'ssh.kbd_interactive_authentication');

        return collect([
            $this->rootLogin(),
            match ($passwordAuthentication) {
                true => new InventoryCheck('Password login', 'On', 'red'),
                false => new InventoryCheck('Password login', 'Off', 'green'),
                default => new InventoryCheck('Password login', 'Unknown', 'zinc'),
            },
            match ($keyboardInteractive) {
                true => new InventoryCheck('Keyboard-interactive login', 'On', 'amber'),
                false => new InventoryCheck('Keyboard-interactive login', 'Off', 'green'),
                default => new InventoryCheck('Keyboard-interactive login', 'Unknown', 'zinc'),
            },
        ]);
    }

    /**
     * Where the SSH settings were read from, for the section description.
     */
    public function sshSource(): ?string
    {
        $source = $this->textAt('ssh.source');

        return $source === null ? null : "Effective settings from {$source}.";
    }

    /**
     * nftables, plus ufw when it is installed. nftables off is only red when ufw is not filtering either.
     *
     * @return Collection<int, InventoryCheck>
     */
    public function firewall(): Collection
    {
        $isUfwActive = data_get($this->data, 'firewall.ufw_active');
        $rules = data_get($this->data, 'firewall.nftables_rule_count');
        $tables = data_get($this->data, 'firewall.nftables_table_count');
        $ruleSummary = is_int($rules) && is_int($tables) ? Str::plural('rule', $rules, prependCount: true).' in '.Str::plural('table', $tables, prependCount: true) : null;

        $nftables = match (true) {
            data_get($this->data, 'firewall.nftables_active') === null => new InventoryCheck('nftables', 'Unknown', 'zinc'),
            data_get($this->data, 'firewall.nftables_active') === false => new InventoryCheck('nftables', 'Inactive', $isUfwActive === true ? 'zinc' : 'red'),
            $rules === 0 => new InventoryCheck('nftables', 'Active, no rules', $isUfwActive === true ? 'zinc' : 'amber'),
            default => new InventoryCheck('nftables', $this->joined(' · ', 'Active', $ruleSummary), 'green'),
        };

        return collect([
            $nftables,
            match ($isUfwActive) {
                true => new InventoryCheck('ufw', 'Active', 'green'),
                false => new InventoryCheck('ufw', 'Inactive', 'zinc'),
                default => null,
            },
        ])->filter()->values();
    }

    public function failedLogins(): InventoryCheck
    {
        $count = data_get($this->data, 'failed_logins.count');

        if (! is_int($count)) {
            return new InventoryCheck('Failed SSH logins', 'Not readable', 'zinc');
        }

        return new InventoryCheck(
            'Failed SSH logins',
            $this->joined(' · ', number_format($count).' in '.(data_get($this->data, 'failed_logins.window_hours') ?? 24).' hours', $this->textAt('failed_logins.source')),
            match (true) {
                $count >= config('inventory.linux_failed_logins_critical') => 'red',
                $count >= config('inventory.linux_failed_logins_warning') => 'amber',
                default => 'green',
            },
        );
    }

    public function failedLoginSources(): InventoryTable
    {
        return $this->table('failed_logins.top_sources', [
            'Address' => fn (array $source): ?string => $this->text($source['ip'] ?? null),
            'Attempts' => fn (array $source): ?string => $this->text($source['count'] ?? null),
        ]);
    }

    /**
     * Null when the agent could not read /etc/passwd, so "no keys" and "not read" stay apart.
     */
    public function authorizedKeys(): ?InventoryTable
    {
        if (! is_array(data_get($this->data, 'authorized_keys'))) {
            return null;
        }

        return $this->table('authorized_keys', [
            'User' => fn (array $key): ?string => $this->text($key['user'] ?? null),
            'Type' => fn (array $key): ?string => $this->text($key['key_type'] ?? null),
            'Comment' => fn (array $key): ?string => $this->text($key['comment'] ?? null),
            'Fingerprint' => fn (array $key): ?string => $this->text($key['fingerprint'] ?? null),
        ]);
    }

    /**
     * The Windows inventory's listening ports, with the protocol first.
     */
    public function listeningPorts(): InventoryTable
    {
        return $this->table('listening_ports', [
            'Protocol' => fn (array $listener): ?string => $this->text($listener['protocol'] ?? null),
            'Port' => fn (array $listener): ?string => $this->text($listener['port'] ?? null),
            'Address' => fn (array $listener): ?string => $this->text($listener['address'] ?? null),
            'Process' => fn (array $listener): ?string => $this->text($listener['process_name'] ?? null),
            'PID' => fn (array $listener): ?string => $this->text($listener['process_id'] ?? null),
        ]);
    }

    private function pendingUpdates(): InventoryCheck
    {
        $pending = data_get($this->data, 'updates.pending');
        $security = data_get($this->data, 'updates.security');

        return match (true) {
            ! is_int($pending) => new InventoryCheck('Pending updates', 'Unknown', 'zinc'),
            is_int($security) && $security > 0 => new InventoryCheck('Pending updates', "{$security} security · {$pending} in all", 'red'),
            $pending === 0 => new InventoryCheck('Pending updates', 'Up to date', 'green'),
            default => new InventoryCheck('Pending updates', "{$pending} pending", $pending >= config('inventory.linux_pending_updates_warning') ? 'amber' : 'green'),
        };
    }

    private function reboot(): InventoryCheck
    {
        $packages = $this->strings('updates.reboot_packages');

        return data_get($this->data, 'updates.reboot_required') === true
            ? new InventoryCheck('Reboot', $this->joined(' · ', 'Required', $packages === [] ? null : implode(', ', $packages)), 'amber')
            : new InventoryCheck('Reboot', 'Not needed', 'green');
    }

    private function unattendedUpgrades(): InventoryCheck
    {
        $lastRun = $this->carbon(data_get($this->data, 'unattended_upgrades.last_run_at'));
        $lastRunText = $lastRun === null ? 'never run' : 'last ran '.$lastRun->diffForHumans();
        $isStale = $lastRun === null || $lastRun->diffInDays(now()) >= config('inventory.linux_unattended_upgrades_stale_days');

        return match (true) {
            data_get($this->data, 'unattended_upgrades.installed') !== true => new InventoryCheck('Unattended upgrades', 'Not installed', 'amber'),
            data_get($this->data, 'unattended_upgrades.enabled') === false => new InventoryCheck('Unattended upgrades', 'Off', 'amber'),
            data_get($this->data, 'unattended_upgrades.enabled') !== true => new InventoryCheck('Unattended upgrades', 'Installed, setting unknown', 'zinc'),
            $isStale => new InventoryCheck('Unattended upgrades', "On · {$lastRunText}", 'amber'),
            default => new InventoryCheck('Unattended upgrades', "On · {$lastRunText}", 'green'),
        };
    }

    /**
     * yes lets root in with a password; the key-only modes still let root in, so they show amber.
     */
    private function rootLogin(): InventoryCheck
    {
        return match ($this->textAt('ssh.permit_root_login')) {
            'yes' => new InventoryCheck('Root login', 'Allowed, password too', 'red'),
            'prohibit-password', 'without-password' => new InventoryCheck('Root login', 'Keys only', 'green'),
            'forced-commands-only' => new InventoryCheck('Root login', 'Forced commands only', 'amber'),
            'no' => new InventoryCheck('Root login', 'Off', 'green'),
            default => new InventoryCheck('Root login', 'Unknown', 'zinc'),
        };
    }
}
