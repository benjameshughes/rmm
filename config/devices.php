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
    |
    */

    'disk' => [
        'warning_percent' => 75,
        'critical_percent' => 90,
    ],

];
