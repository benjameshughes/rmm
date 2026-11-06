<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Staff Accounts
    |--------------------------------------------------------------------------
    |
    | Local Windows accounts the RMM creates and keeps in step on the office
    | PCs an account targets (sync-local-user). The RMM keeps no passwords:
    | creating, resetting or (when asked) adopting an account sets temp_password
    | and makes the person change it at their next logon. A plain sync never
    | touches the password, unless it has to create the user on a PC new to
    | the account. It is not a secret, but it reaches the PC only when the
    | agent fetches the command (StaffTempPassword), and blank once the
    | account is deleted, so a waiting sync never recreates it.
    |
    | Windows caps a local user name at 20 characters and refuses " / \ [ ]
    | : ; | = , + * ? < > @, so names stick to letters, digits, dot, dash and
    | underscore.
    |
    */

    'username_pattern' => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/',

    'full_name_max_length' => 255,

    'temp_password' => 'password',

    // A password chosen on the Staff page (set-local-password). It is kept
    // encrypted in command_secrets only until the agent fetches the command;
    // one never fetched is pruned after handover_ttl_minutes.
    'password_min_length' => 12,

    'password_max_length' => 127,

    'password_handover_ttl_minutes' => 1440,

];
