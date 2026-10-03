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
    | Heartbeat And Online Status
    |--------------------------------------------------------------------------
    |
    | Agents heartbeat every interval_seconds; the server sends this value in
    | each heartbeat reply, so changing it here retunes every agent without a
    | release. A device is online until it misses missed_heartbeats in a row.
    | devices:check-offline runs every minute and announces devices that went
    | quiet within the last announce_window_seconds past that point, so open
    | pages flip to Offline over Reverb without a refresh.
    |
    */

    'heartbeat' => [
        'interval_seconds' => 15,
    ],

    'online' => [
        'missed_heartbeats' => 3,
        'announce_window_seconds' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Power State
    |--------------------------------------------------------------------------
    |
    | The agent posts to /api/power the moment Windows starts sleeping or
    | shutting down, and again when it wakes or boots. A device powering off
    | shows as such until it checks in again, for at most
    | powering_off_max_hours: one that never wakes (a crash, a dead PSU) then
    | falls back to Offline and offline alerting. A check-in landing within
    | powering_off_check_in_grace_seconds of the notice was already in flight
    | when sleep began, so it does not clear it. Powering on is held for
    | powering_on_hold_seconds so the badge is seen before regular check-ins
    | settle it back to Online.
    |
    */

    'power' => [
        'powering_on_hold_seconds' => 15,
        'shutdown_script_slugs' => ['restart', 'shutdown'],
        'powering_off_max_hours' => 72,
        'powering_off_check_in_grace_seconds' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Disk Usage Thresholds
    |--------------------------------------------------------------------------
    |
    | Used-space percentages at which a disk is shown as filling up or full.
    | Volumes matching an ignored pattern (Str::is wildcards) are not stored
    | or alerted on: Windows EFI/recovery partitions have no drive letter and
    | sit 70-90% full by design, as do Linux boot and runtime mounts. Linux
    | hosts also report Docker and container storage layers, pseudo
    | filesystems and RAM-backed tmpfs, matched by mount point or filesystem.
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
            '/proc*',
            '/var/lib/docker*',
            '/var/lib/containers*',
            '/var/lib/lxcfs*',
        ],
        'ignored_filesystems' => [
            'tmpfs',
            'devtmpfs',
            'overlay',
            'squashfs',
            'proc',
            'sysfs',
            'fuse.lxcfs',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Device List
    |--------------------------------------------------------------------------
    |
    | Devices shown per page on the device list.
    |
    */

    'list' => [
        'per_page' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | CPU And RAM Thresholds
    |--------------------------------------------------------------------------
    |
    | Usage percentages at which the device list colours a CPU or RAM bar as
    | busy or maxed out.
    |
    */

    'load' => [
        'warning_percent' => 70,
        'critical_percent' => 90,
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
    | The device Metrics tab charts each range averaged into buckets of
    | bucket_seconds, so a week is ~170 points rather than ~40,000 reports.
    | recent_commands is how many commands the Overview tab lists.
    |
    */

    'metrics' => [
        'store_raw_payload' => (bool) env('DEVICE_METRICS_STORE_RAW_PAYLOAD', false),
        'top_apps' => 10,
        'app_history_hours' => 24,
        'recent_commands' => 5,
        'chart_ranges' => [
            '1h' => ['minutes' => 60, 'bucket_seconds' => 60],
            '24h' => ['minutes' => 1440, 'bucket_seconds' => 600],
            '7d' => ['minutes' => 10080, 'bucket_seconds' => 3600],
        ],
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
    | The prepare script is queued on every Windows device when it is approved.
    |
    */

    'wake_on_lan' => [
        'broadcast_address' => env('WAKE_ON_LAN_BROADCAST_ADDRESS', '255.255.255.255'),
        'port' => (int) env('WAKE_ON_LAN_PORT', 9),
        'packets_per_mac' => 3,
        'prepare_script_slug' => 'prepare-wake-on-lan',
    ],

];
