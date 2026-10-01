<?php

namespace Database\Seeders;

use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use App\Models\Script;
use Illuminate\Database\Seeder;

class ScriptSeeder extends Seeder
{
    public function run(): void
    {
        collect($this->systemScripts())->each(fn (array $script) => Script::create($script));
    }

    private function systemScripts(): array
    {
        return [
            [
                'name' => 'Shutdown',
                'description' => 'Force shutdown the computer immediately',
                'category' => ScriptCategory::Power,
                'platform' => ScriptPlatform::Windows,
                'script_type' => ScriptType::Powershell,
                'script_content' => 'Stop-Computer -Force',
                'is_system' => true,
                'timeout_seconds' => 60,
                'requires_admin' => true,
            ],
            [
                'name' => 'Restart',
                'description' => 'Force restart the computer immediately',
                'category' => ScriptCategory::Power,
                'platform' => ScriptPlatform::Windows,
                'script_type' => ScriptType::Powershell,
                'script_content' => 'Restart-Computer -Force',
                'is_system' => true,
                'timeout_seconds' => 60,
                'requires_admin' => true,
            ],
            [
                'name' => 'Log Off',
                'description' => 'Log off the current user session',
                'category' => ScriptCategory::Power,
                'platform' => ScriptPlatform::Windows,
                'script_type' => ScriptType::Cmd,
                'script_content' => 'logoff',
                'is_system' => true,
                'timeout_seconds' => 30,
                'requires_admin' => false,
            ],
            [
                'name' => 'IP Configuration',
                'description' => 'Display full network adapter configuration',
                'category' => ScriptCategory::Network,
                'platform' => ScriptPlatform::Windows,
                'script_type' => ScriptType::Powershell,
                'script_content' => 'Get-NetIPConfiguration | Format-List',
                'is_system' => true,
                'timeout_seconds' => 30,
                'requires_admin' => false,
            ],
            [
                'name' => 'Flush DNS',
                'description' => 'Clear the DNS resolver cache',
                'category' => ScriptCategory::Network,
                'platform' => ScriptPlatform::Windows,
                'script_type' => ScriptType::Cmd,
                'script_content' => 'ipconfig /flushdns',
                'is_system' => true,
                'timeout_seconds' => 30,
                'requires_admin' => true,
            ],
            [
                'name' => 'Network Interfaces',
                'description' => 'Show all network interfaces and addresses',
                'category' => ScriptCategory::Network,
                'platform' => ScriptPlatform::Linux,
                'script_type' => ScriptType::Bash,
                'script_content' => 'ip addr show',
                'is_system' => true,
                'timeout_seconds' => 30,
                'requires_admin' => false,
            ],
            [
                'name' => 'System Info',
                'description' => 'Display detailed system information',
                'category' => ScriptCategory::Info,
                'platform' => ScriptPlatform::Windows,
                'script_type' => ScriptType::Powershell,
                'script_content' => 'systeminfo',
                'is_system' => true,
                'timeout_seconds' => 120,
                'requires_admin' => false,
            ],
            [
                'name' => 'Process List',
                'description' => 'List all running processes',
                'category' => ScriptCategory::Processes,
                'platform' => ScriptPlatform::Windows,
                'script_type' => ScriptType::Powershell,
                'script_content' => 'Get-Process | Sort-Object CPU -Descending | Select-Object -First 20 Name, CPU, WorkingSet, Id',
                'is_system' => true,
                'timeout_seconds' => 30,
                'requires_admin' => false,
            ],
            [
                'name' => 'Process List',
                'description' => 'List all running processes sorted by CPU usage',
                'category' => ScriptCategory::Processes,
                'platform' => ScriptPlatform::Linux,
                'script_type' => ScriptType::Bash,
                'script_content' => 'ps aux --sort=-%cpu | head -20',
                'is_system' => true,
                'timeout_seconds' => 30,
                'requires_admin' => false,
            ],
            [
                'name' => 'Disk Usage',
                'description' => 'Show disk space usage for all mounted filesystems',
                'category' => ScriptCategory::Info,
                'platform' => ScriptPlatform::Linux,
                'script_type' => ScriptType::Bash,
                'script_content' => 'df -h',
                'is_system' => true,
                'timeout_seconds' => 30,
                'requires_admin' => false,
            ],
            [
                'name' => 'List Services',
                'description' => 'List all Windows services and their status',
                'category' => ScriptCategory::Services,
                'platform' => ScriptPlatform::Windows,
                'script_type' => ScriptType::Powershell,
                'script_content' => 'Get-Service | Sort-Object Status, Name | Format-Table Name, Status, StartType -AutoSize',
                'is_system' => true,
                'timeout_seconds' => 60,
                'requires_admin' => false,
            ],
            [
                'name' => 'Systemd Services',
                'description' => 'List all active systemd services',
                'category' => ScriptCategory::Services,
                'platform' => ScriptPlatform::Linux,
                'script_type' => ScriptType::Bash,
                'script_content' => 'systemctl list-units --type=service --state=running',
                'is_system' => true,
                'timeout_seconds' => 30,
                'requires_admin' => false,
            ],
            [
                'name' => 'Firewall Status',
                'description' => 'Check Windows Firewall profile status',
                'category' => ScriptCategory::Security,
                'platform' => ScriptPlatform::Windows,
                'script_type' => ScriptType::Powershell,
                'script_content' => 'Get-NetFirewallProfile | Format-Table Name, Enabled, DefaultInboundAction, DefaultOutboundAction',
                'is_system' => true,
                'timeout_seconds' => 30,
                'requires_admin' => true,
            ],
            [
                'name' => 'Open Ports',
                'description' => 'List all listening ports and associated processes',
                'category' => ScriptCategory::Security,
                'platform' => ScriptPlatform::Linux,
                'script_type' => ScriptType::Bash,
                'script_content' => 'ss -tulnp',
                'is_system' => true,
                'timeout_seconds' => 30,
                'requires_admin' => true,
            ],
            [
                'name' => 'Windows Update',
                'description' => 'Check for and install all available Windows updates',
                'category' => ScriptCategory::Updates,
                'platform' => ScriptPlatform::Windows,
                'script_type' => ScriptType::Powershell,
                'script_content' => 'Get-WindowsUpdate -Install -AcceptAll -AutoReboot',
                'is_system' => true,
                'timeout_seconds' => 1800,
                'requires_admin' => true,
            ],
            [
                'name' => 'Memory Usage',
                'description' => 'Show memory usage details',
                'category' => ScriptCategory::Info,
                'platform' => ScriptPlatform::Linux,
                'script_type' => ScriptType::Bash,
                'script_content' => 'free -h',
                'is_system' => true,
                'timeout_seconds' => 30,
                'requires_admin' => false,
            ],
        ];
    }
}
