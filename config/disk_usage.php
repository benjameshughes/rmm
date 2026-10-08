<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Disk Usage Scans
    |--------------------------------------------------------------------------
    |
    | The disk-usage script runs `rmm du` on a Windows PC and prints the
    | folder tree as one line of JSON (schema rmm.du/1). Each successful run
    | is kept as a scan for the device's Storage tab; only the newest
    | scans_kept per device and scanned folder are kept, so the tab can show
    | what grew or shrank since the previous one.
    |
    */

    'slug' => 'disk-usage',

    'schema' => 'rmm.du/1',

    'default_path' => 'C:\\',

    'default_depth' => 4,

    'min_depth' => 1,

    'max_depth' => 6,

    'path_pattern' => '/^[A-Za-z]:\\\\[^"*?<>|\x00-\x1F]*$/',

    'scans_kept' => 5,

    /*
    |--------------------------------------------------------------------------
    | Scan On Low Disk
    |--------------------------------------------------------------------------
    |
    | When a disk usage alert opens for a Windows PC, its fullest drive is
    | scanned so the Storage tab already shows what filled it. At most one
    | automatic scan per device and drive in this many hours.
    |
    */

    'auto_scan_cooldown_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Space Hogs
    |--------------------------------------------------------------------------
    |
    | Well-known folders that fill drives. Each glob is relative to the drive
    | root and `*` stands for one folder name. The agent is told to keep every
    | glob as its own node even when the tree is pruned (`--keep`), and the
    | Storage tab lists what it found under the label. `owner` says what the
    | first `*` names: a user profile folder, or a user SID (Recycle Bin).
    | They reach the agent with the command only (see `secrets` in
    | config/scripts.php), so they are never editable on the PC.
    |
    */

    'culprits' => [
        ['label' => 'Outlook data', 'glob' => 'Users\\*\\AppData\\Local\\Microsoft\\Outlook', 'owner' => 'user'],
        ['label' => 'Downloads', 'glob' => 'Users\\*\\Downloads', 'owner' => 'user'],
        ['label' => 'Temp files', 'glob' => 'Users\\*\\AppData\\Local\\Temp', 'owner' => 'user'],
        ['label' => 'Chrome cache', 'glob' => 'Users\\*\\AppData\\Local\\Google\\Chrome\\User Data\\*\\Cache', 'owner' => 'user'],
        ['label' => 'Chrome cache', 'glob' => 'Users\\*\\AppData\\Local\\Google\\Chrome\\User Data\\*\\Code Cache', 'owner' => 'user'],
        ['label' => 'Edge cache', 'glob' => 'Users\\*\\AppData\\Local\\Microsoft\\Edge\\User Data\\*\\Cache', 'owner' => 'user'],
        ['label' => 'Edge cache', 'glob' => 'Users\\*\\AppData\\Local\\Microsoft\\Edge\\User Data\\*\\Code Cache', 'owner' => 'user'],
        ['label' => 'Recycle Bin', 'glob' => '$Recycle.Bin\\*', 'owner' => 'sid'],
        ['label' => 'Windows Update cache', 'glob' => 'Windows\\SoftwareDistribution\\Download', 'owner' => null],
        ['label' => 'Previous Windows install', 'glob' => 'Windows.old', 'owner' => null],
        ['label' => 'Windows upgrade files', 'glob' => '$WINDOWS.~BT', 'owner' => null],
        ['label' => 'Windows Installer cache', 'glob' => 'Windows\\Installer', 'owner' => null],
        ['label' => 'Crash reports', 'glob' => 'ProgramData\\Microsoft\\Windows\\WER', 'owner' => null],
        ['label' => 'System Restore points', 'glob' => 'System Volume Information', 'owner' => null],
    ],

    /*
    | Files in the drive root that Windows itself sizes, found in the scan's
    | largest files.
    */

    'root_files' => [
        'pagefile.sys' => 'Page file',
        'hiberfil.sys' => 'Hibernation file',
        'swapfile.sys' => 'Swap file',
    ],

    /*
    | The keep globs travel as one value split on this character, which no
    | Windows path can contain.
    */

    'keep_separator' => '|',

    'top_files_shown' => 15,

    'extensions_shown' => 10,

];
