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

    /*
    |--------------------------------------------------------------------------
    | Unstarted Command Requeue
    |--------------------------------------------------------------------------
    |
    | Fetching a command marks it sent, and the agent reports it started
    | before running it. A sent command that has not started within this many
    | seconds was dropped by the agent (sleep began mid-fetch, a crash, the
    | network went), so it is put back in the queue to be offered again.
    |
    */

    'unstarted_requeue_seconds' => 60,

    /*
    |--------------------------------------------------------------------------
    | Ad-hoc Commands
    |--------------------------------------------------------------------------
    |
    | Limits for commands typed straight into a device's Run Command box
    | rather than saved as a script. The label length is how much of the
    | command's first line the recent commands list shows.
    |
    */

    'ad_hoc' => [
        'max_length' => 10000,
        'label_max_length' => 60,
        'timeout_seconds' => [
            'default' => 300,
            'min' => 10,
            'max' => 7200,
        ],
    ],

];
