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

    'linux_installer' => resource_path('scripts/installer/install.sh'),

    'linux_netdata_installer' => resource_path('scripts/installer/install-netdata.sh'),

    'types' => [
        'ps1' => 'powershell',
        'cmd' => 'cmd',
        'sh' => 'bash',
    ],

    /*
    |--------------------------------------------------------------------------
    | Script Parameters
    |--------------------------------------------------------------------------
    |
    | Scripts may declare parameters. The agent hands each value to the script
    | as an `RMM_<Name>` environment variable, so names must be valid variable
    | names and values are never spliced into the script text. System scripts
    | declare theirs under a `parameters` key below.
    |
    */

    'parameters' => [
        'name_pattern' => '/^[A-Za-z][A-Za-z0-9_]{0,63}$/',
        'max_per_script' => 20,
        'max_label_length' => 255,
        'max_value_length' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Progress Lines
    |--------------------------------------------------------------------------
    |
    | A long-running script may report progress while it runs by printing
    | lines like this to stdout, flushed straight away
    | ([Console]::Out.WriteLine then [Console]::Out.Flush() in PowerShell, so
    | they are not caught by a pipeline or held in a buffer):
    |
    |   PROGRESS: {"schema":"rmm.progress/1","percent":42.5,"done":7612,
    |     "total":18128,"unit":"files","bytes_done":1288490188,
    |     "bytes_total":3328599654,"eta_seconds":312,"message":"Backing up",
    |     "current":"C:\\Users\\sophie\\Documents\\x.xlsx"}
    |
    | One JSON object per line. Every field but schema is optional; percent
    | is 0 to 100. The agent posts the newest line to the server at most
    | every 5 seconds, only when it changed, and leaves these lines out of
    | the command's output, so the verdict and the final JSON line are
    | unaffected. The Backups tab, busy buttons and the command detail show
    | it while the command runs. backup-files.ps1 (via Invoke-Restic in
    | shared/restic.ps1) is the reference. See config/commands.php.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Includes and Secrets
    |--------------------------------------------------------------------------
    |
    | A system script may list `includes`: shared files under `path` that the
    | sync puts in front of its own file, so scripts share one copy of the
    | same setup code.
    |
    | A system script may also list `secrets`: values the server hands the
    | agent alongside the parameters, as RMM_<Name> environment variables,
    | only when the agent fetches the command. They are worked out at that
    | moment by ResolveCommandSecrets and never stored with the command, its
    | parameters or the audit log. A script with secrets needs an agent that
    | passes parameters on.
    |
    */

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
        'prepare-wake-on-lan' => [
            'name' => 'Prepare Wake-on-LAN',
            'description' => 'Arm wired adapters for magic packets and disable Fast Startup. Wake-on-LAN must still be enabled in the BIOS',
            'category' => 'power',
            'platform' => 'windows',
            'file' => 'windows/prepare-wake-on-lan.ps1',
            'timeout_seconds' => 120,
            'requires_admin' => true,
        ],
        'install-netdata' => [
            'name' => 'Install Netdata',
            'description' => 'Install the pinned Netdata release the agent reads its metrics from. Skips a PC that already has it running',
            'category' => 'services',
            'platform' => 'windows',
            'file' => 'windows/install-netdata.ps1',
            'timeout_seconds' => 600,
            'requires_admin' => true,
        ],
        'repair-netdata' => [
            'name' => 'Repair Netdata',
            'description' => 'Get Netdata reporting CPU again: restart a stopped or hung service, and rebuild a corrupt Windows performance counter list. Queued automatically when a PC stops reporting CPU',
            'category' => 'maintenance',
            'platform' => 'windows',
            'file' => 'windows/repair-netdata.ps1',
            'timeout_seconds' => 600,
            'requires_admin' => true,
        ],
        'never-sleep' => [
            'name' => 'Never Sleep',
            'description' => 'Set the PC to never sleep and the screen to turn off after 60 minutes',
            'category' => 'power',
            'platform' => 'windows',
            'file' => 'windows/never-sleep.ps1',
            'timeout_seconds' => 60,
            'requires_admin' => true,
        ],
        'remove-teams' => [
            'name' => 'Remove Teams',
            'description' => 'Remove Microsoft Teams and its Outlook add-in for every user, and stop Office reinstalling it',
            'category' => 'maintenance',
            'platform' => 'windows',
            'file' => 'windows/remove-teams.ps1',
            'timeout_seconds' => 300,
            'requires_admin' => true,
        ],
        'debloat-windows' => [
            'name' => 'Debloat Windows',
            'description' => 'Remove preinstalled consumer apps and Dell SupportAssist for every user, block OneDrive sync, switch off widgets, Bing search, Copilot, AI features and ads, and stop suggested apps installing themselves. Safe to run daily',
            'category' => 'maintenance',
            'platform' => 'windows',
            'file' => 'windows/debloat-windows.ps1',
            'timeout_seconds' => 600,
            'requires_admin' => true,
        ],
        'disk-cleanup' => [
            'name' => 'Disk Cleanup',
            'description' => 'Free space on the system drive: week-old temp files, crash dumps, the update delivery cache and superseded Windows components. Never touches documents, downloads or recycle bins',
            'category' => 'maintenance',
            'platform' => 'windows',
            'file' => 'windows/disk-cleanup.ps1',
            'timeout_seconds' => 1800,
            'requires_admin' => true,
        ],
        'install-restic' => [
            'name' => 'Install Restic',
            'description' => 'Install the pinned restic release the file backups use, checked against its published sha256. Skips a PC that already has it',
            'category' => 'backup',
            'platform' => 'windows',
            'file' => 'windows/install-restic.ps1',
            'includes' => ['windows/shared/restic.ps1'],
            'secrets' => ['ResticVersion', 'ResticDownloadUrl', 'ResticSha256', 'ResticExeSha256'],
            'timeout_seconds' => 600,
            'requires_admin' => true,
        ],
        'backup-files' => [
            'name' => 'Back Up Files',
            'description' => 'Back up every user profile to the backup server with restic, from a shadow copy, skipping caches, temp files and anything over 4 GB. Creates the PC\'s repository on its first run. Does nothing on a PC without backups enabled',
            'category' => 'backup',
            'platform' => 'windows',
            'file' => 'windows/backup-files.ps1',
            'includes' => ['windows/shared/restic.ps1'],
            'secrets' => ['RestUrl', 'RestCaCert', 'RepositoryName', 'ResticPassword', 'ResticVersion', 'ResticDownloadUrl', 'ResticSha256', 'ResticExeSha256', 'MasterPassword'],
            'timeout_seconds' => 7200,
            'requires_admin' => true,
            'parameters' => [
                ['name' => 'StaggerMinutes', 'label' => 'Random start delay, up to (minutes)', 'type' => 'number', 'required' => false, 'default' => '30'],
                ['name' => 'UploadLimitKiB', 'label' => 'Upload limit (KiB/s, 0 for none)', 'type' => 'number', 'required' => false, 'default' => '0'],
            ],
        ],
        'backup-snapshots' => [
            'name' => 'List Backups',
            'description' => 'List the PC\'s backup snapshots on the backup server, for its Backups tab',
            'category' => 'backup',
            'platform' => 'windows',
            'file' => 'windows/backup-snapshots.ps1',
            'includes' => ['windows/shared/restic.ps1'],
            'secrets' => ['RestUrl', 'RestCaCert', 'RepositoryName', 'ResticPassword', 'ResticVersion', 'ResticDownloadUrl', 'ResticSha256', 'ResticExeSha256'],
            'timeout_seconds' => 600,
            'requires_admin' => true,
        ],
        'backup-restore' => [
            'name' => 'Restore Backup',
            'description' => 'Restore files from a backup snapshot into a new folder, never overwriting anything. Source Device restores another PC\'s backup onto this one',
            'category' => 'backup',
            'platform' => 'windows',
            'file' => 'windows/backup-restore.ps1',
            'includes' => ['windows/shared/restic.ps1'],
            'secrets' => ['RestUrl', 'RestCaCert', 'RepositoryName', 'ResticPassword', 'ResticVersion', 'ResticDownloadUrl', 'ResticSha256', 'ResticExeSha256'],
            'timeout_seconds' => 7200,
            'requires_admin' => true,
            'parameters' => [
                ['name' => 'SnapshotId', 'label' => 'Snapshot ID', 'type' => 'text', 'required' => true, 'default' => 'latest'],
                ['name' => 'IncludePath', 'label' => 'Only this file or folder', 'type' => 'text', 'required' => false],
                ['name' => 'Target', 'label' => 'Restore into folder', 'type' => 'text', 'required' => false],
                ['name' => 'SourceDevice', 'label' => 'Source device ID', 'type' => 'number', 'required' => false],
            ],
        ],
        'clear-print-queue' => [
            'name' => 'Clear Print Queue',
            'description' => 'Remove every job from one printer\'s queue. Jobs stuck at Deleting need the print spooler restarted',
            'category' => 'maintenance',
            'platform' => 'windows',
            'file' => 'windows/clear-print-queue.ps1',
            'timeout_seconds' => 120,
            'requires_admin' => true,
            'parameters' => [
                ['name' => 'PrinterName', 'label' => 'Printer Name', 'type' => 'text', 'required' => true],
            ],
        ],
        'cancel-print-job' => [
            'name' => 'Cancel Print Job',
            'description' => 'Remove one job from one printer\'s queue by its job number',
            'category' => 'maintenance',
            'platform' => 'windows',
            'file' => 'windows/cancel-print-job.ps1',
            'timeout_seconds' => 60,
            'requires_admin' => true,
            'parameters' => [
                ['name' => 'PrinterName', 'label' => 'Printer Name', 'type' => 'text', 'required' => true],
                ['name' => 'JobId', 'label' => 'Job ID', 'type' => 'number', 'required' => true],
            ],
        ],
        'restart-print-spooler' => [
            'name' => 'Restart Print Spooler',
            'description' => 'Restart the Windows print spooler, killing it if it hangs, and wait for it to run again. Releases stuck jobs',
            'category' => 'maintenance',
            'platform' => 'windows',
            'file' => 'windows/restart-print-spooler.ps1',
            'timeout_seconds' => 120,
            'requires_admin' => true,
        ],
        'print-test-page' => [
            'name' => 'Print Test Page',
            'description' => 'Print the Windows test page on one printer',
            'category' => 'maintenance',
            'platform' => 'windows',
            'file' => 'windows/print-test-page.ps1',
            'timeout_seconds' => 60,
            'requires_admin' => true,
            'parameters' => [
                ['name' => 'PrinterName', 'label' => 'Printer Name', 'type' => 'text', 'required' => true],
            ],
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
        'winget-upgrade-all' => [
            'name' => 'Upgrade All Apps (winget)',
            'description' => 'Silently upgrade every machine-wide app winget knows about, installing winget first if it is missing',
            'category' => 'updates',
            'platform' => 'windows',
            'file' => 'windows/winget-upgrade-all.ps1',
            'timeout_seconds' => 3600,
            'requires_admin' => true,
        ],
        'winget-install' => [
            'name' => 'Install App (winget)',
            'description' => 'Silently install one app machine-wide by its winget package ID, such as Mozilla.Firefox, installing winget first if it is missing',
            'category' => 'updates',
            'platform' => 'windows',
            'file' => 'windows/winget-install.ps1',
            'timeout_seconds' => 1800,
            'requires_admin' => true,
            'parameters' => [
                ['name' => 'PackageId', 'label' => 'Package ID', 'type' => 'text', 'required' => true],
            ],
        ],
        'winget-inventory' => [
            'name' => 'Software Inventory (winget)',
            'description' => 'List every installed app with its version and whether winget has an update, for the Software pages. Installs winget first if it is missing',
            'category' => 'updates',
            'platform' => 'windows',
            'file' => 'windows/winget-inventory.ps1',
            'timeout_seconds' => 600,
            'requires_admin' => true,
        ],
        'winget-upgrade' => [
            'name' => 'Upgrade App (winget)',
            'description' => 'Silently upgrade one machine-wide app by its winget package ID, such as Mozilla.Firefox, installing winget first if it is missing',
            'category' => 'updates',
            'platform' => 'windows',
            'file' => 'windows/winget-upgrade.ps1',
            'timeout_seconds' => 1800,
            'requires_admin' => true,
            'parameters' => [
                ['name' => 'PackageId', 'label' => 'Package ID', 'type' => 'text', 'required' => true],
                ['name' => 'CloseApp', 'label' => 'Close the app first', 'type' => 'boolean', 'required' => false],
            ],
        ],
        'winget-uninstall' => [
            'name' => 'Uninstall App (winget)',
            'description' => 'Silently uninstall one app by its package ID from the software inventory, installing winget first if it is missing',
            'category' => 'updates',
            'platform' => 'windows',
            'file' => 'windows/winget-uninstall.ps1',
            'timeout_seconds' => 1800,
            'requires_admin' => true,
            'parameters' => [
                ['name' => 'PackageId', 'label' => 'Package ID', 'type' => 'text', 'required' => true],
                ['name' => 'CloseApp', 'label' => 'Close the app first', 'type' => 'boolean', 'required' => false],
            ],
        ],
        'update-agent' => [
            'name' => 'Update Agent',
            'description' => 'Install the latest RMM agent release. The agent restarts itself mid-run, so the command completes when the device reports its new version',
            'category' => 'updates',
            'platform' => 'windows',
            'file' => 'windows/update-agent.ps1',
            'timeout_seconds' => 300,
            'requires_admin' => true,
        ],
        'patch-status' => [
            'name' => 'Patch Status',
            'description' => 'Check when the PC was last patched, which updates are waiting and whether a restart is pending. Fails when patching needs attention',
            'category' => 'updates',
            'platform' => 'windows',
            'file' => 'windows/patch-status.ps1',
            'timeout_seconds' => 900,
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
        'disk-usage' => [
            'name' => 'Disk Usage Scan',
            'description' => 'Measure what fills a drive or folder, folder by folder, with the biggest files and known space hogs, for the device Storage tab. Changes nothing',
            'category' => 'maintenance',
            'platform' => 'windows',
            'file' => 'windows/disk-usage.ps1',
            'secrets' => ['DiskUsageKeep'],
            'timeout_seconds' => 900,
            'requires_admin' => true,
            'parameters' => [
                ['name' => 'Path', 'label' => 'Drive or folder', 'type' => 'text', 'required' => false, 'default' => 'C:\\'],
                ['name' => 'Depth', 'label' => 'Folder levels (1 to 6)', 'type' => 'number', 'required' => false, 'default' => '4'],
            ],
        ],
        'remove-path' => [
            'name' => 'Delete Path',
            'description' => 'Delete one file or folder, or move it into quarantine on the system drive. Never follows junctions or symlinks, and refuses Windows, program and profile folders. Queued from the Storage tab or the device header, which confirm it first',
            'category' => 'maintenance',
            'platform' => 'windows',
            'file' => 'windows/remove-path.ps1',
            'includes' => ['windows/shared/remove-path.ps1'],
            'secrets' => ['DeletePathProtected'],
            'timeout_seconds' => 3600,
            'requires_admin' => true,
            'parameters' => [
                ['name' => 'Path', 'label' => 'File or folder', 'type' => 'text', 'required' => true],
                ['name' => 'Mode', 'label' => 'Mode', 'type' => 'choice', 'required' => true, 'default' => 'delete', 'options' => ['delete', 'quarantine']],
                ['name' => 'ExpectedKind', 'label' => 'Expected kind', 'type' => 'choice', 'required' => true, 'default' => 'any', 'options' => ['file', 'folder', 'any']],
            ],
        ],
        'purge-quarantine' => [
            'name' => 'Purge Quarantine',
            'description' => 'Delete quarantined files and folders older than the given number of days, or one quarantine folder now. Queued daily for PCs holding quarantined items',
            'category' => 'maintenance',
            'platform' => 'windows',
            'file' => 'windows/purge-quarantine.ps1',
            'includes' => ['windows/shared/remove-path.ps1'],
            'timeout_seconds' => 3600,
            'requires_admin' => true,
            'parameters' => [
                ['name' => 'Days', 'label' => 'Older than (days)', 'type' => 'number', 'required' => true, 'default' => '7'],
                ['name' => 'Folder', 'label' => 'Only this quarantine folder', 'type' => 'text', 'required' => false],
            ],
        ],
        'restore-quarantine' => [
            'name' => 'Restore From Quarantine',
            'description' => 'Move one quarantined file or folder back where it came from, unless something already exists there',
            'category' => 'maintenance',
            'platform' => 'windows',
            'file' => 'windows/restore-quarantine.ps1',
            'includes' => ['windows/shared/remove-path.ps1'],
            'secrets' => ['DeletePathProtected'],
            'timeout_seconds' => 600,
            'requires_admin' => true,
            'parameters' => [
                ['name' => 'Folder', 'label' => 'Quarantine folder', 'type' => 'text', 'required' => true],
                ['name' => 'Path', 'label' => 'Restore to', 'type' => 'text', 'required' => true],
            ],
        ],
        'system-inventory' => [
            'name' => 'System Inventory',
            'description' => 'Read the hardware, Windows, security, users, updates and third-party drivers, services and tasks, for the device System tab. Changes nothing',
            'category' => 'info',
            'platform' => 'windows',
            'file' => 'windows/system-inventory.ps1',
            'timeout_seconds' => 600,
            'requires_admin' => true,
        ],
        'installed-software' => [
            'name' => 'Installed Software',
            'description' => 'List installed applications with version, publisher and install date',
            'category' => 'info',
            'platform' => 'windows',
            'file' => 'windows/installed-software.ps1',
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
        'event-log-check' => [
            'name' => 'Event Log Check',
            'description' => 'Look for blue screens, unexpected shutdowns, disk errors and repeated failed logons in the last 24 hours. Fails when something needs attention',
            'category' => 'security',
            'platform' => 'windows',
            'file' => 'windows/event-log-check.ps1',
            'timeout_seconds' => 120,
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
        'linux-disk-usage' => [
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
