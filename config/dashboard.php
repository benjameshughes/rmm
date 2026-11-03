<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard Sections
    |--------------------------------------------------------------------------
    |
    | How many rows each dashboard section shows before linking on to the
    | full page. Disk thresholds come from devices.disk.
    |
    */

    'attention_rows' => 8,
    'worth_knowing_rows' => 3,
    'offline_names' => 3,
    'hero_segments' => 40,
    'disk_rows' => 4,
    'busiest_devices' => 5,

    /*
    |--------------------------------------------------------------------------
    | Charts
    |--------------------------------------------------------------------------
    |
    | Alerts are counted over the latest window (and the one before it, for
    | the delta) in buckets of alert_bucket_seconds. Busy devices draw their
    | CPU over sparkline_hours in buckets of sparkline_bucket_seconds.
    |
    */

    'alert_window_hours' => 24,
    'alert_bucket_seconds' => 3600,
    'sparkline_hours' => 24,
    'sparkline_bucket_seconds' => 1800,
    'fleet_load_hours' => 24,
    'fleet_load_bucket_seconds' => 1800,

    /*
    | The disks ring counts each device's fullest disk into grey bands split
    | at these percentages, then amber from devices.disk.warning_percent and
    | red from devices.disk.critical_percent.
    */

    'disk_bands' => [50, 65],

    /*
    |--------------------------------------------------------------------------
    | Live Refresh
    |--------------------------------------------------------------------------
    |
    | Every agent heartbeat broadcasts DeviceUpdated, so a busy fleet would
    | re-render the dashboard every second or two. Heartbeats only redraw it
    | when the last redraw is at least this old; alerts, enrolments and new
    | agent releases always redraw at once.
    |
    */

    'heartbeat_refresh_seconds' => 10,

];
