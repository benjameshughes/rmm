<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | System Inventory
    |--------------------------------------------------------------------------
    |
    | The system-inventory script prints the PC's hardware, Windows, security,
    | users, updates and third-party extras as one line of JSON. Every
    | successful run is kept as a snapshot, the newest one is shown on the
    | device's System tab.
    |
    */

    'system_slug' => 'system-inventory',

    /*
    |--------------------------------------------------------------------------
    | Defender Signatures
    |--------------------------------------------------------------------------
    |
    | Defender updates its signatures several times a day, so signatures this
    | many days old show amber, then red.
    |
    */

    'defender_signature_warning_days' => 3,
    'defender_signature_critical_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Device Problems
    |--------------------------------------------------------------------------
    |
    | Windows gives every device that is not working a problem code. This one
    | means somebody disabled the device, so it shows amber where the rest
    | show red.
    |
    */

    'device_disabled_problem_code' => 22,

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    */

    'fleet_per_page' => 25,

];
