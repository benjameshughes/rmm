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

    /*
    |--------------------------------------------------------------------------
    | Stale Command Grace Period
    |--------------------------------------------------------------------------
    |
    | Extra seconds allowed on top of a command's own timeout before a sent or
    | running command is considered abandoned and marked as timed out.
    |
    */

    'stale_grace_seconds' => 300,

];
