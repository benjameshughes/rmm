<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bell Limit
    |--------------------------------------------------------------------------
    |
    | How many of the latest notifications the bell dropdown shows.
    |
    */

    'bell_limit' => 8,

    /*
    |--------------------------------------------------------------------------
    | Scheduled Script Failed Alert
    |--------------------------------------------------------------------------
    |
    | The built-in alert rule created the first time a scheduled script
    | finishes. Scheduled scripts exit non-zero when something needs
    | attention and print a one-line summary first; that line, cut to
    | summary_max_length characters, becomes the alert message. Switch the
    | rule off under Alert Rules to stop raising these alerts.
    |
    */

    'scheduled_script_failed' => [
        'rule_name' => 'Scheduled script failed',
        'severity' => 'warning',
        'summary_max_length' => 200,
    ],

];
