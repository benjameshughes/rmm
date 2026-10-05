<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

use Illuminate\Support\Collection;

/**
 * BitLocker, Secure Boot, TPM, Defender, firewall and UAC from one system inventory.
 */
final class InventorySecurity
{
    use ReadsInventoryData;

    /**
     * @param  array<string, mixed>  $data  The inventory's security section
     */
    public function __construct(
        private readonly array $data,
    ) {}

    /**
     * Null when the system drive's BitLocker state was not reported.
     */
    public function isSystemDriveProtected(): ?bool
    {
        $systemDrive = $this->rows('bitlocker')->first(fn (array $volume): bool => ($volume['is_system_drive'] ?? false) === true);

        return $systemDrive === null ? null : ($systemDrive['protection_status'] ?? null) === 'On';
    }

    /**
     * @return Collection<int, InventoryCheck>
     */
    public function checks(): Collection
    {
        return collect([
            $this->secureBoot(),
            $this->tpm(),
            $this->defender(),
            $this->defenderSignatures(),
            $this->uac(),
            $this->virtualizationBasedSecurity(),
        ])->filter()->values();
    }

    /**
     * @return Collection<int, InventoryCheck>
     */
    public function bitlocker(): Collection
    {
        return $this->rows('bitlocker')->map(function (array $volume): InventoryCheck {
            $status = $this->text($volume['protection_status'] ?? null);
            $isSystemDrive = ($volume['is_system_drive'] ?? false) === true;

            return new InventoryCheck(
                $this->joined(' ', $this->text($volume['drive'] ?? null) ?? 'Volume', $isSystemDrive ? '(system)' : null),
                $this->joined(' · ', $status ?? 'Unknown', $this->text($volume['conversion_status'] ?? null)),
                match (true) {
                    $status === 'On' => 'green',
                    $status === 'Off' && $isSystemDrive => 'red',
                    $status === 'Off' => 'amber',
                    default => 'zinc',
                },
            );
        });
    }

    /**
     * @return Collection<int, InventoryCheck>
     */
    public function firewall(): Collection
    {
        return $this->rows('firewall')->map(fn (array $profile): InventoryCheck => ($profile['is_enabled'] ?? false) === true
            ? new InventoryCheck($this->text($profile['name'] ?? null) ?? 'Profile', 'On', 'green')
            : new InventoryCheck($this->text($profile['name'] ?? null) ?? 'Profile', 'Off', 'red'));
    }

    private function secureBoot(): InventoryCheck
    {
        return match (data_get($this->data, 'secure_boot')) {
            true => new InventoryCheck('Secure Boot', 'On', 'green'),
            false => new InventoryCheck('Secure Boot', 'Off', 'red'),
            default => new InventoryCheck('Secure Boot', 'Not supported', 'zinc'),
        };
    }

    private function tpm(): InventoryCheck
    {
        $isReady = data_get($this->data, 'tpm.is_enabled') === true && data_get($this->data, 'tpm.is_activated') === true;

        return match (true) {
            data_get($this->data, 'tpm.is_present') !== true => new InventoryCheck('TPM', 'Not found', 'red'),
            $isReady => new InventoryCheck('TPM', $this->joined(' · ', 'Ready', $this->textAt('tpm.spec_version')), 'green'),
            default => new InventoryCheck('TPM', 'Present, not ready', 'amber'),
        };
    }

    private function defender(): InventoryCheck
    {
        return match (true) {
            ! is_array(data_get($this->data, 'defender')) => new InventoryCheck('Defender', 'Not reporting', 'zinc'),
            data_get($this->data, 'defender.is_real_time_enabled') === true => new InventoryCheck('Defender', 'Real-time protection on', 'green'),
            default => new InventoryCheck('Defender', 'Real-time protection off', 'red'),
        };
    }

    private function defenderSignatures(): ?InventoryCheck
    {
        if (! is_array(data_get($this->data, 'defender'))) {
            return null;
        }

        $updatedAt = $this->carbon(data_get($this->data, 'defender.signature_updated_at'));
        $ageInDays = $updatedAt?->diffInDays(now());

        return new InventoryCheck(
            'Defender signatures',
            $this->joined(' · ', $updatedAt ? 'Updated '.$updatedAt->diffForHumans() : 'Last update unknown', $this->textAt('defender.signature_version')),
            match (true) {
                $ageInDays === null => 'zinc',
                $ageInDays >= config('inventory.defender_signature_critical_days') => 'red',
                $ageInDays >= config('inventory.defender_signature_warning_days') => 'amber',
                default => 'green',
            },
        );
    }

    /**
     * ConsentPromptBehaviorAdmin 0 lets administrators elevate without being asked.
     */
    private function uac(): InventoryCheck
    {
        return match (true) {
            data_get($this->data, 'uac.is_enabled') === false => new InventoryCheck('UAC', 'Off', 'red'),
            data_get($this->data, 'uac.is_enabled') !== true => new InventoryCheck('UAC', 'Unknown', 'zinc'),
            data_get($this->data, 'uac.consent_prompt_admin') === 0 => new InventoryCheck('UAC', 'On, admins never prompted', 'amber'),
            default => new InventoryCheck('UAC', 'On', 'green'),
        };
    }

    private function virtualizationBasedSecurity(): ?InventoryCheck
    {
        $status = $this->textAt('device_guard.vbs_status');

        if ($status === null) {
            return null;
        }

        $credentialGuard = data_get($this->data, 'device_guard.is_credential_guard_running') === true ? 'Credential Guard on' : 'Credential Guard off';

        return new InventoryCheck('Virtualisation-based security', "{$status} · {$credentialGuard}", $status === 'Running' ? 'green' : 'zinc');
    }
}
