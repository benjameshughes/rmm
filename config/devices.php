<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Agent Authentication
    |--------------------------------------------------------------------------
    |
    | Failed device-key attempts allowed per IP per minute before that IP is
    | locked out. Valid keys are never counted.
    |
    */

    'auth' => [
        'max_failed_attempts_per_minute' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Disk Usage Thresholds
    |--------------------------------------------------------------------------
    |
    | Used-space percentages at which a disk is shown as filling up or full.
    | Volumes matching an ignored pattern (Str::is wildcards) are not stored
    | or alerted on: Windows EFI/recovery partitions have no drive letter and
    | sit 70-90% full by design, as do Linux boot and runtime mounts.
    |
    */

    'disk' => [
        'warning_percent' => 75,
        'critical_percent' => 90,
        'ignored_volumes' => [
            'HarddiskVolume*',
            '/boot*',
            '/run*',
            '/dev*',
            '/sys*',
            '/snap*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Storage
    |--------------------------------------------------------------------------
    |
    | Metrics are extracted into columns and the raw request is discarded.
    | Turn this on to keep each raw request in device_metrics.payload while
    | debugging the agent; it grows the table quickly, so switch it off after.
    |
    | Each report keeps the top_apps busiest apps by CPU plus the top_apps
    | biggest by memory. App rows are pruned after app_history_hours.
    |
    */

    'metrics' => [
        'store_raw_payload' => (bool) env('DEVICE_METRICS_STORE_RAW_PAYLOAD', false),
        'top_apps' => 10,
        'app_history_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Network Adapters
    |--------------------------------------------------------------------------
    |
    | Adapters matching an ignored pattern (Str::is wildcards) are not stored.
    | Windows lists virtual, tunnelling and loopback adapters alongside the
    | real NICs; they only add noise.
    |
    */

    'network' => [
        'ignored_interfaces' => [
            '*Loopback*',
            '*Hyper-V*',
            'vEthernet*',
            '*WAN Miniport*',
            '*Bluetooth*',
            'Teredo*',
            'isatap*',
        ],
    ],

];
