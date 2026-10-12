<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | ZDesigner Driver
    |--------------------------------------------------------------------------
    |
    | install-zebra puts the label stations' Zebra GK420d printers on Zebra's
    | own ZDesigner driver: v5 (5.1.17.7415), since the GK420d stops working
    | on v10. Zebra's download sits behind a licence click with short-lived
    | signed links, so the driver is self-hosted: unpack
    | zd51177415-certified.exe (7-Zip, or run it once and take the folder it
    | extracts to), zip the result with the ZBRN folder inside, put it on the
    | file host and pin its sha256 here. The zip is checked before anything
    | is installed.
    |
    | `inf` is the INF's path inside the zip, `name` the model name in it,
    | which is also the printer driver name and the new queue's name. A queue
    | is captured as the Zebra template, or restored onto, only when its
    | driver name starts with `prefix`.
    |
    | All of it reaches install-zebra only when the agent fetches the command
    | (see `secrets` in config/scripts.php).
    |
    */

    'driver' => [
        'url' => env('ZEBRA_DRIVER_URL'),
        'sha256' => env('ZEBRA_DRIVER_SHA256'),
        'inf' => env('ZEBRA_DRIVER_INF', 'ZBRN/ZBRN.inf'),
        'name' => env('ZEBRA_DRIVER_NAME', 'ZDesigner GK420d'),
        'prefix' => 'ZDesigner',
    ],

    /*
    |--------------------------------------------------------------------------
    | Zebra Queues
    |--------------------------------------------------------------------------
    |
    | A print queue is a Zebra one when its name or driver name matches any
    | of these .NET regular expressions, any case. That covers the ZDesigner
    | queue and the old Seagull ones ("Zebra GK420d - ZPL", "#2", "(Copy 1)").
    | install-zebra keeps one ZDesigner queue on the Zebra's USB port and
    | removes every other match, then the Seagull Zebra drivers left unused.
    |
    */

    'match' => [
        'Zebra',
        'ZDesigner',
        'GK420',
    ],

    /*
    |--------------------------------------------------------------------------
    | Timing and Template Size
    |--------------------------------------------------------------------------
    |
    | When no Zebra queue holds a USB port, install-zebra has Windows rescan
    | its devices and waits up to pnp_wait_seconds for Plug and Play to
    | create the queue on the new driver.
    |
    | The template travels to the PC as one environment variable, which
    | Windows caps at 32,767 characters, so a capture bigger than
    | template_max_chars (base64 of the gzipped settings) is refused.
    |
    */

    'pnp_wait_seconds' => 60,

    'template_max_chars' => 32000,

];
