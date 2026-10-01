<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | System Scripts
    |--------------------------------------------------------------------------
    |
    | Built-in scripts shipped with the app. Each file lives under `path` and
    | is synced into the scripts table by `php artisan scripts:sync`, keyed by
    | slug. The script type comes from the file extension.
    |
    */

    'path' => resource_path('scripts'),

    'installer' => resource_path('scripts/installer/install.ps1'),

    'types' => [
        'ps1' => 'powershell',
        'cmd' => 'cmd',
        'sh' => 'bash',
    ],

    'system' => [
        'shutdown' => [
            'name' => 'Shutdown',
            'description' => 'Force shutdown the computer immediately',
            'category' => 'power',
            'platform' => 'windows',
            'file' => 'windows/shutdown.ps1',
            'timeout_seconds' => 60,
            'requires_admin' => true,
        ],
        'restart' => [
            'name' => 'Restart',
            'description' => 'Force restart the computer immediately',
            'category' => 'power',
            'platform' => 'windows',
            'file' => 'windows/restart.ps1',
            'timeout_seconds' => 60,
            'requires_admin' => true,
        ],
        'log-off' => [
            'name' => 'Log Off',
            'description' => 'Log off every interactive user session',
            'category' => 'user',
            'platform' => 'windows',
            'file' => 'windows/log-off.ps1',
            'timeout_seconds' => 60,
            'requires_admin' => true,
        ],
        'windows-update' => [
            'name' => 'Windows Update (Patches)',
            'description' => 'Install security and quality patches, skipping feature upgrades (does not restart)',
            'category' => 'updates',
            'platform' => 'windows',
            'file' => 'windows/windows-update.ps1',
            'timeout_seconds' => 3600,
            'requires_admin' => true,
        ],
        'windows-feature-upgrade' => [
            'name' => 'Windows Feature Upgrade',
            'description' => 'Install a new Windows version such as 25H2. Expect a long restart (does not restart)',
            'category' => 'updates',
            'platform' => 'windows',
            'file' => 'windows/windows-feature-upgrade.ps1',
            'timeout_seconds' => 7200,
            'requires_admin' => true,
        ],
        'update-agent' => [
            'name' => 'Update Agent',
            'description' => 'Install the latest RMM agent release. The agent restarts itself, so this often ends as Timed Out: the update badge disappearing is the real confirmation',
            'category' => 'updates',
            'platform' => 'windows',
            'file' => 'windows/update-agent.ps1',
            'timeout_seconds' => 300,
            'requires_admin' => true,
        ],
        'ip-configuration' => [
            'name' => 'IP Configuration',
            'description' => 'Display full network adapter configuration',
            'category' => 'network',
            'platform' => 'windows',
            'file' => 'windows/ip-configuration.ps1',
            'timeout_seconds' => 30,
            'requires_admin' => false,
        ],
        'flush-dns' => [
            'name' => 'Flush DNS',
            'description' => 'Clear the DNS resolver cache',
            'category' => 'network',
            'platform' => 'windows',
            'file' => 'windows/flush-dns.cmd',
            'timeout_seconds' => 30,
            'requires_admin' => true,
        ],
        'system-info' => [
            'name' => 'System Info',
            'description' => 'Display detailed system information',
            'category' => 'info',
            'platform' => 'windows',
            'file' => 'windows/system-info.ps1',
            'timeout_seconds' => 120,
            'requires_admin' => false,
        ],
        'process-list' => [
            'name' => 'Process List',
            'description' => 'List the top 20 processes by CPU',
            'category' => 'processes',
            'platform' => 'windows',
            'file' => 'windows/process-list.ps1',
            'timeout_seconds' => 30,
            'requires_admin' => false,
        ],
        'list-services' => [
            'name' => 'List Services',
            'description' => 'List all Windows services and their status',
            'category' => 'services',
            'platform' => 'windows',
            'file' => 'windows/list-services.ps1',
            'timeout_seconds' => 60,
            'requires_admin' => false,
        ],
        'firewall-status' => [
            'name' => 'Firewall Status',
            'description' => 'Check Windows Firewall profile status',
            'category' => 'security',
            'platform' => 'windows',
            'file' => 'windows/firewall-status.ps1',
            'timeout_seconds' => 30,
            'requires_admin' => true,
        ],
        'network-interfaces' => [
            'name' => 'Network Interfaces',
            'description' => 'Show all network interfaces and addresses',
            'category' => 'network',
            'platform' => 'linux',
            'file' => 'linux/network-interfaces.sh',
            'timeout_seconds' => 30,
            'requires_admin' => false,
        ],
        'linux-process-list' => [
            'name' => 'Process List',
            'description' => 'List the top 20 processes by CPU',
            'category' => 'processes',
            'platform' => 'linux',
            'file' => 'linux/process-list.sh',
            'timeout_seconds' => 30,
            'requires_admin' => false,
        ],
        'disk-usage' => [
            'name' => 'Disk Usage',
            'description' => 'Show disk space usage for all mounted filesystems',
            'category' => 'info',
            'platform' => 'linux',
            'file' => 'linux/disk-usage.sh',
            'timeout_seconds' => 30,
            'requires_admin' => false,
        ],
        'systemd-services' => [
            'name' => 'Systemd Services',
            'description' => 'List all running systemd services',
            'category' => 'services',
            'platform' => 'linux',
            'file' => 'linux/systemd-services.sh',
            'timeout_seconds' => 30,
            'requires_admin' => false,
        ],
        'open-ports' => [
            'name' => 'Open Ports',
            'description' => 'List all listening ports and their processes',
            'category' => 'security',
            'platform' => 'linux',
            'file' => 'linux/open-ports.sh',
            'timeout_seconds' => 30,
            'requires_admin' => true,
        ],
        'memory-usage' => [
            'name' => 'Memory Usage',
            'description' => 'Show memory usage details',
            'category' => 'info',
            'platform' => 'linux',
            'file' => 'linux/memory-usage.sh',
            'timeout_seconds' => 30,
            'requires_admin' => false,
        ],
    ],

];
