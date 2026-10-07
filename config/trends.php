<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Periods
    |--------------------------------------------------------------------------
    |
    | Each period compares its last `hours` with the same length just before.
    | The fleet charts lay both over one another, averaged into buckets of
    | bucket_seconds, so each line has a hundred or so points.
    |
    */

    'periods' => [
        '24h' => ['hours' => 24, 'bucket_seconds' => 900],
        '7d' => ['hours' => 168, 'bucket_seconds' => 3600],
        '30d' => ['hours' => 720, 'bucket_seconds' => 21600],
    ],

    /*
    |--------------------------------------------------------------------------
    | Measuring
    |--------------------------------------------------------------------------
    |
    | A device needs min_reports reports in a period (about half an hour of
    | reporting) before its figures for it count. Only devices with enough in
    | both periods make up the fleet comparison, so a PC joining or leaving
    | never moves the fleet average on its own.
    |
    | Hours online are the online_bucket_seconds slots holding at least one
    | report. A reboot is a report whose uptime is more than
    | reboot_uptime_tolerance_seconds below the report before it. CPU peaks
    | are the peak_percentile of each device's reports (nearest rank).
    |
    */

    'min_reports' => 30,
    'online_bucket_seconds' => 300,
    'reboot_uptime_tolerance_seconds' => 60,
    'peak_percentile' => 95,

    /*
    |--------------------------------------------------------------------------
    | Steady Thresholds
    |--------------------------------------------------------------------------
    |
    | A difference no bigger than this, in the metric's own unit, reads as no
    | real change and is shown grey instead of green or red. Percentages are
    | percentage points, page file is MiB.
    |
    */

    'steady' => [
        'cpu' => 1,
        'cpu_peak' => 2,
        'ram' => 1,
        'swap' => 256,
        'disk_busy' => 1,
        'reboots' => 0,
        'online_hours' => 1,
        'blank_rate' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Changes In The Same Period
    |--------------------------------------------------------------------------
    |
    | Commands that only read and report (inventories, status checks) change
    | nothing on the PC, so they are left out of the changes list, and so is
    | the nightly backup, which would otherwise fill it every day. Each change
    | lists up to device_chips devices before linking on.
    |
    */

    'ignored_script_slugs' => [
        'winget-inventory',
        'system-inventory',
        'system-info',
        'installed-software',
        'patch-status',
        'backup-snapshots',
        'backup-files',
        'ip-configuration',
        'process-list',
        'list-services',
        'firewall-status',
        'event-log-check',
        'network-interfaces',
        'linux-process-list',
        'disk-usage',
        'systemd-services',
        'open-ports',
        'memory-usage',
    ],

    'device_chips' => 12,
    'device_change_labels' => 3,

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | The comparison reads every report in both periods, so it is kept for
    | cache_seconds and the page says when its figures are from.
    |
    */

    'cache_seconds' => 300,
    'cache_key' => 'trends.comparison',

];
