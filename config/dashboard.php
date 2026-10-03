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

    'disk_rows' => 10,
    'recent_alerts' => 5,
    'recent_activity' => 6,
    'busiest_devices' => 5,

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
