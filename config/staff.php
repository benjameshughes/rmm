<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Staff Accounts
    |--------------------------------------------------------------------------
    |
    | Local Windows accounts the RMM creates and keeps in step on the office
    | PCs an account targets (sync-local-user). The password reaches the PC
    | only when the agent fetches the command, as the StaffPassword secret.
    |
    | Windows caps a local user name at 20 characters and refuses " / \ [ ]
    | : ; | = , + * ? < > @, so names stick to letters, digits, dot, dash and
    | underscore.
    |
    */

    'username_pattern' => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/',

    'full_name_max_length' => 255,

    'password_min_length' => 12,

    'password_max_length' => 127,

    'generated_password_length' => 16,

    /*
    |--------------------------------------------------------------------------
    | Screen Lock
    |--------------------------------------------------------------------------
    |
    | set-screen-lock locks every session on a PC after this many idle
    | minutes, machine-wide. Queued from the Staff page to every PC a staff
    | account targets.
    |
    */

    'screen_lock_idle_minutes' => (int) env('STAFF_SCREEN_LOCK_IDLE_MINUTES', 10),

];
