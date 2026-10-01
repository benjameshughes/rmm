<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Command Output
    |--------------------------------------------------------------------------
    |
    | The agent reports stdout and stderr as one string, with stderr appended
    | after this separator. Keep it in sync with STDERR_SEPARATOR in the
    | agent's command_runner.rs.
    |
    */

    'stderr_separator' => "\n\n--- stderr ---\n",

];
