<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Local Accounts
    |--------------------------------------------------------------------------
    |
    | The Windows agent reports every local account with its metrics, every
    | few minutes, from this version on. Older agents send nothing, so their
    | Users tab says so instead of showing an empty list.
    |
    */

    'min_agent_version' => '0.11.0',

    'max_reported' => 500,

    /*
    |--------------------------------------------------------------------------
    | Built-in Accounts
    |--------------------------------------------------------------------------
    |
    | Administrator, Guest, DefaultAccount and WDAGUtilityAccount, found by
    | the last part of their SID so a renamed one is still caught. Never
    | adopted, disabled or deleted from the RMM.
    |
    */

    'built_in_rids' => ['500', '501', '503', '504'],

];
