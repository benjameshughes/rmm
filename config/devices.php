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
    | Online Status
    |--------------------------------------------------------------------------
    |
    | A device is online while it has reported within threshold_minutes.
    | devices:check-offline runs every minute and announces devices that went
    | quiet within the last announce_window_seconds past the threshold, so
    | open pages flip to Offline over Reverb without a refresh.
    |
    */

    'online' => [
        'threshold_minutes' => 5,
        'announce_window_seconds' => 120,
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
    | real NICs; they only add noise. Ignored MAC addresses are dropped from
    | what the agent reports, so they are never sent a wake packet.
    |
    */

    'network' => [
        'ignored_mac_addresses' => [
            '00:00:00:00:00:00',
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Wake-on-LAN
    |--------------------------------------------------------------------------
    |
    | Magic packets are broadcast from this server, so it needs a leg on the
    | devices' LAN and the broadcast address of that subnet. 255.255.255.255
    | leaves through the default route, which is usually the wrong network.
    | Each MAC gets packets_per_mac packets: a sleeping NIC can miss one.
    |
    */

    'wake_on_lan' => [
        'broadcast_address' => env('WAKE_ON_LAN_BROADCAST_ADDRESS', '255.255.255.255'),
        'port' => (int) env('WAKE_ON_LAN_PORT', 9),
        'packets_per_mac' => 3,
    ],

];
